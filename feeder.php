#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';
include_once __DIR__.'/config.inc.php';

$debug = false;
if(defined('DEBUG_MODE') || (isset($argv[1]) && $argv[1] == '-d'))
    $debug = true;

$beacon = new BPX\Beacon(BPX_HOST, BPX_PORT, BPX_CRT, BPX_KEY);
$pdo = NULL;

while(true) {
    try {
        if($debug) echo "Next iteration\n";
        
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
        $pdo -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo -> setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo -> setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        $networkInfo = $beacon -> getNetworkInfo();
        $blockchainState = $beacon -> getBlockchainState();
        
        $task = [
            ':timestamp' => time() - (24 * 60 * 60)
        ];
        
        $sql = 'DELETE FROM netspace
                WHERE timestamp < :timestamp';
        
        $q = $pdo -> prepare($sql);
        $q -> execute($task);
        
        if($debug) echo "Deleted old netspace records\n";
        
        $task = [
            ':timestamp' => time(),
            ':netspace' => $blockchainState -> space
        ];
        
        $sql = 'INSERT INTO netspace(
                    timestamp,
                    netspace
                ) VALUES(
                    :timestamp,
                    :netspace
                )';
        
        $q = $pdo -> prepare($sql);
        $q -> execute($task);
        
        if($debug) echo "Inserted netspace record\n";
        
        $q = $pdo -> query("SELECT netspace FROM netspace ORDER BY timestamp ASC LIMIT 1");
        $netspacePrev = $q -> fetch();
        
        $task = [
            ':network_name' => $networkInfo -> network_name,
            ':peak_height' => $blockchainState -> peak -> height,
            ':difficulty' => $blockchainState -> difficulty,
            ':netspace' => $blockchainState -> space,
            ':netspace_prev' => $netspacePrev['netspace']
        ];
        
        $sql = 'UPDATE state
                SET network_name = :network_name,
                    peak_height = :peak_height,
                    difficulty = :difficulty,
                    netspace = :netspace,
                    netspace_prev = :netspace_prev';
        
        $q = $pdo -> prepare($sql);
        $q -> execute($task);
        
        if($debug) echo "Updated state\n";
        
        // Step 1. Get height and hash of the latest block in database
        // Set -1, NULL if database empty
        $dbHeight = -1;
        $dbHash = NULL;
        $q = $pdo -> query("SELECT height, hash FROM blocks ORDER BY height DESC LIMIT 1");
        $row = $q -> fetch();
        if($row) {
            $dbHeight = $row['height'];
            $dbHash = $row['hash'];
        }
        if($debug) echo "DB height: $dbHeight\nDB hash: $dbHash\n";
        
        // Step 2. If at least 1 block exists in database, check for potential reorg
        // by backwards fetching node blocks by height and comparing node block hash to database
        // block hash. If the hash matches, there was no reorg, if the hash differs, check previous block
        
        if($dbHeight >= 0) {
            if($debug) echo "Checking for reorgs\n";
            
            while(true) {
                $record = $beacon -> getBlockRecordByHeight($dbHeight);
                if($debug) echo "Height = $dbHeight, DB hash = $dbHash, node hash = ".$record -> header_hash." ";
                if($record -> header_hash == $dbHash) {
                    if($debug) echo "(good)\n";
                    break;
                }
                if($debug) echo "(reorg)\n";
                $dbHeight--;
                if($dbHeight == -1)
                    break;
                $q = $pdo -> prepare("SELECT hash FROM blocks WHERE height = :height");
                $q -> execute([':height' => $dbHeight]);
                $row = $q -> fetch();
                if(!$row)
                    throw new Exception('Block expected in database but not available');
                $dbHash = $row['hash'];
            }
        }
        
        // Step 3. Start fetching and adding/replacing blocks to database from dbHeight + 1 to the latest blocks
        // known by node
        while(true) {
            $dbHeight++;
            
            $record = $beacon -> getBlockRecordByHeight($dbHeight);
            $block = $beacon -> getBlock($record -> header_hash);
            
            $jsonBlock = json_encode($block, JSON_UNESCAPED_SLASHES);
            
            $task = [
                ':height' => $dbHeight,
                ':hash' => $record -> header_hash,
                ':coinbase' => $record -> coinbase,
                ':body' => $jsonBlock,
                ':timestamp' => NULL,
                ':execution_block_hash' => NULL,
                ':fee_recipient' => NULL,
                ':wd_addresses' => NULL
            ];
            
            if(isset($record -> timestamp))
                $task[':timestamp'] = $record -> timestamp;
            
            if(isset($record -> execution_block_hash))
                $task[':execution_block_hash'] = $record -> execution_block_hash;
            
            if(isset($block -> execution_payload -> feeRecipient))
                $task[':fee_recipient'] = $block -> execution_payload -> feeRecipient;
            
            if(!empty($block -> execution_payload -> withdrawals)) {
                $task[':wd_addresses'] = '';
                foreach($block -> execution_payload -> withdrawals as $wd) {
                    if($task[':wd_addresses'] != '') $task[':wd_addresses'] .= ',';
                    $task[':wd_addresses'] .= $wd -> address;
                }
            }
            
            $sql = 'REPLACE INTO blocks(height, hash, coinbase, body, timestamp, execution_block_hash, fee_recipient, wd_addresses)
                    VALUES(:height, :hash, :coinbase, :body, :timestamp, :execution_block_hash, :fee_recipient, :wd_addresses)';
                    
            $q = $pdo -> prepare($sql);
            $q -> execute($task);
            
            if($debug) echo "Inserted block: height = $dbHeight\n";
            
            foreach($block -> finished_sub_slots as $fss) {
                if($fss -> challenge_chain -> new_difficulty) {
                    $task = [
                        ':epoch_height' => $dbHeight
                    ];
                    $sql = 'UPDATE state
                            SET epoch_height = :epoch_height';
                    
                    $q = $pdo -> prepare($sql);
                    $q -> execute($task);
                    
                    if($debug) echo "Updated epoch height to $dbHeight\n";
                    
                    break;
                }
            }
        }
    }
    
    catch(Exception $e) {
        echo get_class($e).': '.$e->getMessage()."\n";
    }
    
    unset($pdo);
    sleep(5);
}

?>

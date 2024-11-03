#!/usr/bin/env php
<?php

require __DIR__.'/vendor/autoload.php';
include_once __DIR__.'/config.inc.php';

function importBlocks($pdo, $beacon, $debug) {
    $ret = [
        'peakHeight' => null,
        'epochHeight' => null,
        'difficulty' => null
    ];
    
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
    
    // Step 2. If at least 1 block exists in the database, check for potential reorg
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
                throw new Exception("Block $dbHeight missing in the database");
            $dbHash = $row['hash'];
        }
    }
    
    // Step 3. Start fetching and adding/replacing blocks to database from dbHeight + 1 to the latest blocks
    // known by node
    
    while(true) {
        $dbHeight++;
        
        try {
            $record = $beacon -> getBlockRecordByHeight($dbHeight);
        }
        catch(BPX\Exceptions\BPXException $e) {
            if(str_contains($e -> getMessage(), 'not found in chain'))
                break;
            throw $e;
        }
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
        
        if($debug) echo "Inserted block $dbHeight\n";
        
        $ret['peakHeight'] = $dbHeight;
        
        foreach($block -> finished_sub_slots as $fss) {
            if($fss -> challenge_chain -> new_difficulty) {
                $ret['epochHeight'] = $dbHeight;
                $ret['difficulty'] = $fss -> challenge_chain -> new_difficulty;
                
                if($debug) echo "New epoch height = $dbHeight, new difficulty = ".$ret['difficulty']."\n";
                
                break;
            }
        }
    }
    
    return $ret;
}

function updateNetspace($pdo, $beacon, $debug) {
    $task = [
        ':timestamp' => time() - (24 * 60 * 60)
    ];
    $sql = 'DELETE FROM netspace
            WHERE timestamp < :timestamp';
    $q = $pdo -> prepare($sql);
    $q -> execute($task);
            
    if($debug) echo "Purged old netspace records\n";
    
    $blockchainState = $beacon -> getBlockchainState();
    
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
    
    if($debug) echo "Inserted current netspace record\n";
    
    $q = $pdo -> query('SELECT netspace FROM netspace ORDER BY timestamp LIMIT 1');
    $row = $q -> fetch();
    
    if($debug) echo 'Got oldest netspace record: '.$row['netspace']."\n";
    
    return [
        'netspaceCurr' => $blockchainState -> space,
        'netspacePrev' => $row['netspace']
    ];
}

function updateState($pdo, $importBlocksStatus, $updateNetspaceStatus, $debug) {
    $q = $pdo -> query(
        'SELECT height, timestamp
         FROM blocks
         WHERE timestamp IS NOT NULL
         ORDER BY height DESC
         LIMIT 1'
    );
    $txPeak = $q -> fetch();
    
    $task = [
        ':height' => $txPeak['height'] - 384
    ];
    $sql = 'SELECT timestamp
            FROM blocks
            WHERE height < :height
            AND timestamp IS NOT NULL
            ORDER BY height DESC
            LIMIT 1';
    $q = $pdo -> prepare($sql);
    $q -> execute($task);
    $txReference = $q -> fetch();
    
    $subSlotTime = intval(($txPeak['timestamp'] - $txReference['timestamp']) / 12);
    
    $task = [
        ':netspace_curr' => $updateNetspaceStatus['netspaceCurr'],
        ':netspace_prev' => $updateNetspaceStatus['netspacePrev'],
        ':sub_slot_time' => $subSlotTime
    ];
    if($importBlocksStatus['peakHeight'] !== null)
        $task[':peak_height'] = $importBlocksStatus['peakHeight'];
    if($importBlocksStatus['epochHeight'] !== null)
        $task[':epoch_height'] = $importBlocksStatus['epochHeight'];
    if($importBlocksStatus['difficulty'] !== null)
        $task[':difficulty_curr'] = $importBlocksStatus['difficulty'];
    
    $sql = 'UPDATE state
            SET netspace_curr = :netspace_curr,
            netspace_prev = :netspace_prev,
            sub_slot_time = :sub_slot_time';
    if($importBlocksStatus['peakHeight'] !== null)
        $sql .= ', peak_height = :peak_height';
    if($importBlocksStatus['epochHeight'] !== null)
         $sql .= ', epoch_height = :epoch_height';
    if($importBlocksStatus['difficulty'] !== null)
        $sql .= ', difficulty_prev = difficulty_curr, difficulty_curr = :difficulty_curr';
        
    $q = $pdo -> prepare($sql);
    $q -> execute($task);
    
    if($debug) echo "State updated\n";
}

$debug = false;
if(defined('DEBUG_MODE') || (isset($argv[1]) && $argv[1] == '-d'))
    $debug = true;

$beacon = new BPX\Beacon(BPX_HOST, BPX_PORT, BPX_CRT, BPX_KEY);

while(true) {
    try {
        if($debug) echo "Next iteration\n";
        
        $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME, DB_USER, DB_PASS);
        $pdo -> setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo -> setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
        $pdo -> setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        
        $importBlocksStatus = importBlocks($pdo, $beacon, $debug);
        $updateNetspaceStatus = updateNetspace($pdo, $beacon, $debug);
        updateState($pdo, $importBlocksStatus, $updateNetspaceStatus, $debug);
    } catch(Exception $e) {
        echo get_class($e).': '.$e->getMessage()."\n";
    }
    
    unset($pdo);
    sleep(5);
}

?>

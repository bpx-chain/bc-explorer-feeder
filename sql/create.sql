CREATE TABLE blocks(
    height bigint not null primary key,
    hash varchar(66) not null,
    timestamp bigint default null,
    coinbase varchar(42) not null,
    body longtext not null,
    execution_block_hash varchar(66) default null,
    fee_recipient varchar(42) default null,
    wd_addresses text default null
);

CREATE TABLE netspace(
    timestamp bigint not null primary key,
    netspace bigint not null
);

CREATE TABLE state(
    network_name varchar(128) not null primary key,
    peak_height bigint not null,
    difficulty bigint not null,
    netspace bigint not null,
    netspace_prev bigint not null,
    epoch_height bigint not null
);

INSERT INTO state(
    network_name,
    peak_height,
    difficulty,
    netspace,
    netspace_prev,
    epoch_height
) VALUES(
    '',
    0,
    0,
    0,
    0,
    0
);
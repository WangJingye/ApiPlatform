<?php

namespace Common\Model;

use Think\Model;

class PlatformFtpConfModel extends Model\RelationModel
{

    protected $tableName = 'platform_ftp';

    protected $_auto = [
        ['create_time', 'time', self::MODEL_INSERT, 'function'],
        ['update_time', 'time', self::MODEL_BOTH, 'function'],
    ];

}
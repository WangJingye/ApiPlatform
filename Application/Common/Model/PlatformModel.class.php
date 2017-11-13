<?php

namespace Common\Model;

use Think\Model;

class PlatformModel extends Model\RelationModel
{

    protected $tableName = 'platform';

    protected $_auto = [
        ['create_time', 'time', self::MODEL_INSERT, 'function'],
        ['update_time', 'time', self::MODEL_BOTH, 'function'],
    ];

}
<?php

namespace Common\Model;

use Think\Model;

class UserModel extends Model\RelationModel
{

    protected $tableName = 'user';

    protected $_auto = [
        ['create_time', 'time', self::MODEL_INSERT, 'function'],
        ['update_time', 'time', self::MODEL_BOTH, 'function'],
    ];

}
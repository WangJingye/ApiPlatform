<?php

namespace Common\Model;

use Think\Model;

class UploadModel extends Model\RelationModel
{

    protected $tableName = 'upload';

    protected $_auto = [
        ['create_time', 'time', self::MODEL_INSERT, 'function'],
        ['update_time', 'time', self::MODEL_BOTH, 'function'],
    ];

}
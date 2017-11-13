<?php

namespace Common\Model;

use Think\Model;

class PlatformWsdlConfModel extends Model\RelationModel
{

    protected $tableName = 'platform_wsdl';

    protected $_auto = [
        ['create_time', 'time', self::MODEL_INSERT, 'function'],
        ['update_time', 'time', self::MODEL_BOTH, 'function'],
    ];

}
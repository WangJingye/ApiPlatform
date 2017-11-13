<?php

namespace Common\Model;

use Think\Model;

class UploadWsdlModel extends Model\RelationModel
{

    protected $tableName = 'upload_wsdl';

    protected $_auto = [
        ['create_time', 'time', self::MODEL_INSERT, 'function'],
        ['update_time', 'time', self::MODEL_BOTH, 'function'],
    ];

}
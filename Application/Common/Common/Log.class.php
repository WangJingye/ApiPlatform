<?php
/**
 * Created by PhpStorm.
 * User: wangjingye
 * Date: 2016/6/18
 * Time: 17:42
 */

namespace Common\Common;

class Log
{

    //请求的url
    public $url;
    //请求参数
    public $params;
    //当前用户名
    public $nickname;
    //user_uuid
    public $uuid;
    //返回结果 数组 格式：['code'=>1,'data'=>[],'msg'=>'']
    public $result;
    //日志保存路径
    public $path;
//
//    public function __GET($name)
//    {
//        return $this->$name;
//    }
//
//    public function __SET($name, $value)
//    {
//        if (property_exists($this, $name)) {
//            $this->$name = $value;
//        }
//    }

    /**
     * 记录系统日志
     * 放在在每个controller的init函数中
     * @param string $path 文件保存的文件夹地址
     * @param string $msg 为空时使用默认格式
     */
    public function sysLog($msg = '')
    {
        $filename = $this->path . '/system_log_' . date('Ymd');
        self::createDir($filename);
        if ($msg == '') {
            $sysLogMsg[] = date('Y-m-d H:i:s');
            $sysLogMsg[] = get_client_ip();
            if ($this->uuid) {
                $sysLogMsg[] = $this->uuid;
            }
            $sysLogMsg[] = $this->url;
            $sysLogMsg[] = $this->params;
            $msg = implode(' ', $sysLogMsg);
        }
        file_put_contents($filename, date('Y-m-d H:i:s ') . $msg . PHP_EOL, FILE_APPEND);
    }

    public function saveLog($msg = '')
    {
        self::createDir($this->path);
        file_put_contents($this->path, $msg ."\r\n", FILE_APPEND);
    }

    /**
     * 添加日志文件夹
     * @param $path
     */
    protected function createDir($path)
    {
        $path = dirname($path);
        if (!file_exists($path)) {
            self::createDir($path);
        }
        if (!file_exists($path)) {
            mkdir($path, 0755);
        }
    }
}
<?php

namespace Home\Controller;


use Common\Common\Log;
use Common\Model\PlatformFtpConfModel;
use Common\Model\PlatformModel;
use Common\Model\PlatformWsdlConfModel;
use Common\Model\TradeKeyModel;
use Common\Model\UserModel;
use Think\Controller;
use Think\Exception;

class BaseController extends Controller
{
    /** @var  Log */
    public $log;
    public $user;
    public $platform;
    public $platformWsdlConf;
    public $platformFtpConf;

    /** @var  \SoapClient $soapClient */
    private $soapClient;
    public $printMessage;
    public $errorCode = 0;

    public function __construct()
    {
        $accessUserActions = [
            'user/logout',
            'user/login'
        ];
        parent::__construct();
        $user = session('user');
        $path = strtolower(CONTROLLER_NAME . '/' . ACTION_NAME);
        $flag = 0;
        if (!$user) {
            if (!in_array($path, $accessUserActions)) {
                $this->redirect('Home/User/login');
            } else {
                $flag = 1;
            }
        }
        if ($flag == 0) {
            $this->user = (new UserModel())->where(['user_id' => $user['user_id']])->find();
            if (!$this->user) {
                session('user', null);
                $this->error('用户信息已失效,请重新登录', 'Home/User/login');
            }

            if (CONTROLLER_NAME != 'Upload' && !$this->user['is_admin']) {
                if (!in_array($path, $accessUserActions)) {
                    $this->error('权限不足');
                }
            }
            if (!$this->user['is_admin']) {
                $this->getPlatformData($this->user['platform_id']);
            }
            $platforms = (new PlatformModel())->select();
            $platformList = [];
            foreach ($platforms as $platform) {
                $platformList[$platform['platform_id']] = $platform['platform_name'];
            }
            $this->assign('user', $this->user);
            $this->assign('platformList', $platformList);
        }
    }

    /**
     * @param $platform_id
     * @throws Exception
     */
    public function getPlatformData($platform_id)
    {
        $this->platform = (new PlatformModel())->where(['platform_id' => $platform_id])->find();
        if (!$this->platform) {
            throw new Exception('店铺不存在');
        }
        $this->log = new Log();
        $this->log->path = RUNTIME_PATH . 'Logs/' . MODULE_NAME . '/' . $this->platform['type_code'];
        if ($this->platform['wsdl_need'] == 1) {
            $PlatformWsdlConfModel = new PlatformWsdlConfModel();
            $platformConf = $PlatformWsdlConfModel->where(['platform_id' => $this->platform['platform_id']])->find();
            if (!$platformConf) {
                throw new Exception('店铺wsdl配置不存在');
            }
            $this->platformWsdlConf = $platformConf;
        }
        if ($this->platform['ftp_need'] == 1) {
            $platformFtpConfModel = new PlatformFtpConfModel();
            $platformFtpConf = $platformFtpConfModel->where(['platform_id' => $this->platform['platform_id']])->find();
            if (!$platformFtpConf) {
                throw new Exception('店铺ftp配置不存在');
            }
            $this->platformFtpConf = $platformFtpConf;
        }
    }

    public function printHandel($msg)
    {
        echo str_repeat(' ', 1024 * 4);

        echo date('Y-m-d H:i:s ') . $msg . "<br />";
        $this->printMessage .= date('Y-m-d H:i:s ') . $msg . "<br />";
        ob_flush();
        flush();
    }

    /**
     * @param $function
     * @param $arg
     * @return array|mixed
     */
    public function createRequest($function, $arg)
    {
        try {

            if (!$this->soapClient) {
                $this->printHandel('创建wsdl');
                if (strpos($this->platformWsdlConf['wsdl'], 'https://') !== false) {
                    $opts = [
                        'ssl' => [
                            'verify_peer' => false
                        ],
                        'https' => [
                            'curl_verify_ssl_peer' => false,
                            'curl_verify_ssl_host' => false
                        ]
                    ];
                    $streamContext = stream_context_create($opts);
                    $options = [
                        'stream_context' => $streamContext
                    ];
                    libxml_disable_entity_loader(false);
                    $this->soapClient = new \SoapClient($this->platformWsdlConf['wsdl'], $options);
                } else {
                    libxml_disable_entity_loader(false);
                    $this->soapClient = new \SoapClient($this->platformWsdlConf['wsdl']);
                }
            }
            $this->soapClient->__setLocation($this->platformWsdlConf['wsdl']);
            $this->log->sysLog(json_encode($arg));
            $result = $this->soapClient->$function($arg);
            $result = json_decode(json_encode($result), true);
            $this->log->sysLog(json_encode($result));

        } catch (Exception $e) {
            $this->printHandel($e->getMessage());
            $result = '';
        }
        return $result;
    }

    public function isoftCreateRequest($function, $arg)
    {
        try {

            if (!$this->soapClient) {
                $this->printHandel('创建wsdl');
                if (strpos($this->platformWsdlConf['wsdl'], 'https://') !== false) {
                    $opts = [
                        'ssl' => [
                            'verify_peer' => false
                        ],
                        'https' => [
                            'curl_verify_ssl_peer' => false,
                            'curl_verify_ssl_host' => false
                        ]
                    ];
                    $streamContext = stream_context_create($opts);
                    libxml_disable_entity_loader(false);
                    $params = [
                        'location' => str_replace('?wsdl', '', $this->platformWsdlConf['wsdl']),
                        'uri' => 'http://tempuri.org/',
                        'stream_context' => $streamContext
                    ];
                    $this->soapClient = new \SoapClient(null, $params);
                } else {
                    libxml_disable_entity_loader(false);
                    $params = [
                        'location' => str_replace('?wsdl', '', $this->platformWsdlConf['wsdl']),
                        'uri' => 'http://tempuri.org/',
                    ];
                    $this->soapClient = new \SoapClient(null, $params);
                }
            }
            $this->soapClient->__setLocation($this->platformWsdlConf['wsdl']);
            $this->log->sysLog(json_encode($arg));
            $result = $this->soapClient->__soapCall($function, $arg);
            $result = json_decode(json_encode($result), true);
            $this->log->sysLog(json_encode($result));

        } catch (Exception $e) {
            $this->printHandel($e->getMessage());
            $result = '';
        }
        return $result;
    }

    /**
     * 添加日志文件夹
     * @param $path
     */
    protected function createDir($path)
    {
        $path = dirname($path);
        if (!file_exists($path)) {
            $this->createDir($path);
        }
        if (!file_exists($path)) {
            mkdir($path, 0755);
        }
    }


    public function getTradeKey()
    {
        $tradeKeyModel = new TradeKeyModel();
        $tradeKey = $tradeKeyModel->where(['platform_id' => $this->platform['platform_id']])->where('create_time>=' . strtotime(date('Y-m-d', time())) . ' and create_time<' . (strtotime(date('Y-m-d', time())) + 86400))->find();
        if (!$tradeKey) {
            $tradeKey['platform_id'] = $this->platform['platform_id'];
            $tradeKey['start_num'] = 1;
            $tradeKey['create_time'] = time();
            $tradeKeyModel->add($tradeKey);
        } else {
            $tradeKey['start_num'] += 1;
            $tradeKeyModel->save($tradeKey);
        }
        return $tradeKey['start_num'];
    }

    public function regularAscii($str)
    {
        $reg = "/^[\x{00}-\x{ff}]*$/u";
        if (preg_match($reg, $str)) {
            return true;
        }
        return false;
    }


    public function httpPost($query_url, $postData)
    {
        $urlAry = parse_url($query_url);

        $post_url = $urlAry['scheme'] . "://" . $urlAry['host'];
        $post_url .= ($urlAry['port']) ? ":" . $urlAry['port'] : "";
        $post_url .= $urlAry['path'];
        if (function_exists('curl_init')) {
            //curl
            $ch = curl_init($post_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_TIMEOUT, 100);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
            $ret = curl_exec($ch);
            if (28 == curl_errno($ch)) {
                curl_close($ch);
                return false;
            }
            curl_close($ch);
        } else {
            //fsockopen
            $post_head = "POST " . $urlAry['path'] . " HTTP/1.1\r\n"
                . "Accept: image/gif, image/x-xbitmap, image/jpeg, image/pjpeg, application/x-shockwave-flash, application/vnd.ms-powerpoint, application/vnd.ms-excel, application/msword, */*\r\n"
                . "Accept-Language: zh-cn\r\n"
                . "Content-Type: application/x-www-form-urlencoded\r\n"
                . "User-Agent: WebClient\r\n"
                . "Host:" . $urlAry['host'] . ":" . $urlAry['port'] . "\r\n"
                . "Content-Length: " . strlen($urlAry['query']) . "\r\n"
                . "Connection: Keep-Alive\r\n"
                . "Cache-Control: no-cache\r\n\r\n";

            $errno = $errstr = '';
            $da = @fsockopen($urlAry['host'], $urlAry['port'], $errno, $errstr, 10);
            if (!$da) {
                return false;
            }
            $response = '';
            fputs($da, $post_head . $urlAry['query']);
            while (!feof($da)) $response .= fgets($da, 1024);

            $response = split("\r\n\r\n", $response);
            $ret = chop($response[1]);
        }
        return $ret;
    }

    public function curlJson($url, $json)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        //类型为json
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json; charset=utf-8'
            )
        );
        if (strpos($url, 'https://') !== false) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        //post传递
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ($json));
        $ret = curl_exec($ch);
        if (28 == curl_errno($ch)) {
            curl_close($ch);
            return false;
        }
        curl_close($ch);
        return $ret;
    }


    public function curlJsonWithAuth($url,$json,$basic_auth){
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        //类型为json
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(
                'Content-Type: application/json; charset=utf-8'
            )
        );
        if (strpos($url, 'https://') !== false) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        }
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        //post传递
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, ($json));
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
        curl_setopt($ch, CURLOPT_USERPWD, $basic_auth);
        $ret = curl_exec($ch);
        if (28 == curl_errno($ch)) {
            curl_close($ch);
            return false;
        }
        curl_close($ch);
        return $ret;
    }
}

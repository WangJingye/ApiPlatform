<?php

namespace Home\Controller;


use Common\Common\Log;
use Common\Model\UploadModel;
use Common\Model\UploadTradeModel;
use Think\Exception;
use Think\Upload\Driver\Ftp;

class UploadController extends BaseController
{
    public $upload;


    public function __construct()
    {
        parent::__construct();
        $this->assign('leftKey', 'Upload');

    }

    public function upload()
    {
        if (IS_POST) {
            $ext = end(explode('.', $_FILES['file']['name']));
            if ($ext != 'xlsx' && $ext != 'xls') {
                $this->error('请上传Excel的文档！');
            }
            $platform_id = $this->platform['platform_id'];
            if ($this->user['is_admin']) {
                if (!isset($_POST['platform_id']) || !$_POST['platform_id']) {
                    $this->error('请选择店铺');
                }
                $platform_id = $_POST['platform_id'];
            }
            $this->getPlatformData($platform_id);
            $uploadModel = new UploadModel();
            $upload = [];
            $upload['file_name'] = $_FILES['file']['name'];
            $upload['platform_id'] = $platform_id;
            $upload['user_id'] = $this->user['user_id'];
            $upload['save_name'] = date('YmdHis') . rand(10000, 99999) . '.' . $ext;
            $savePath = APP_PATH . 'Upload/' . $this->platform['type_code'] . '/' . $upload['save_name'];
            $this->createDir($savePath);
            if (move_uploaded_file($_FILES['file']['tmp_name'], $savePath)) {
                $uploadModel->create($upload);
                $uploadModel->add($uploadModel->data());
            }
            $this->redirect('index');
        }
        $this->display();
    }

    public function index()
    {
        $uploadModel = new UploadModel();
        $where = [];
        if (!$this->user['is_admin']) {
            $where = ['platform_id' => $this->platform['platform_id']];
        }
        $list = $uploadModel->where($where)->order('create_time desc')->select();
        $this->assign('list', $list);
        $this->display();

    }

    public function delete()
    {
        if (!isset($_GET['id']) || !$_GET['id']) {
            $this->error('参数有误！');
        }
        $uploadModel = new UploadModel();
        $upload = $uploadModel->where(['id' => $_GET['id']])->find();
        if ($upload['status'] == 1) {
            $this->error('参数有误！');
        }
        if ($this->user['is_admin'] != 1) {
            if ($upload['user_id'] != $this->user['user_id']) {
                $this->error('参数有误！');
            }
        }
        unlink(APP_PATH . 'Upload/' . $this->platform['type_code'] . '/' . $upload['save_name']);
        $uploadModel->where(['id' => $_GET['id']])->delete();
        $this->redirect('index');

    }

    public function seeLog()
    {
        if (!isset($_GET['id']) || !$_GET['id']) {
            $this->error('参数有误！');
        }
        $uploadModel = new UploadModel();
        $upload = $uploadModel->where(['id' => $_GET['id']])->find();
        if ($upload['status'] != 1) {
            $this->error('参数有误！');
        }
        if ($this->user['is_admin'] != 1) {
            if ($upload['user_id'] != $this->user['user_id']) {
                $this->printHandel('参数有误！');
                return;
            }
        }
        echo $upload['log_data'];
    }

    public function complete()
    {
        ob_clean();
        if (!isset($_GET['id']) || !$_GET['id']) {
            $this->printHandel('参数有误！');
            return;
        }
        $uploadModel = new UploadModel();
        $this->upload = $uploadModel->where(['id' => $_GET['id']])->find();
        if (!$this->upload || $this->upload['status'] == 1) {
            $this->printHandel('参数有误！');
            return;
        }
        if (!$this->user['is_admin']) {
            if ($this->upload['user_id'] != $this->user['user_id']) {
                $this->printHandel('参数有误！');
                return;
            }
        }
        $this->dataHandle();

        $this->upload['status'] = 1;
        $this->upload['log_data'] = $this->printMessage;
        $uploadModel->create($this->upload);
        $uploadModel->save($uploadModel->data());
    }

    private function dataHandle()
    {

        $ext = end(explode('.', $this->upload['save_name']));
        if ($ext == 'xlsx') {
            $renderType = 'Excel2007';
        } else {
            $renderType = 'Excel5';
        }
        $this->getPlatformData($this->upload['platform_id']);
        import("Org.Util.PHPExcel");

        import("Org.Util.PHPExcel.IOFactory");
        try {
            $reader = \PHPExcel_IOFactory::createReader($renderType);
        } catch (Exception $e) {
            $this->printHandel('Excel文件有误！');
            return;
        }
        $file_name = APP_PATH . 'Upload/' . $this->platform['type_code'] . '/' . $this->upload['save_name'];
        $PHPExcel = $reader->load($file_name); // 文档名称
        $objWorksheet = $PHPExcel->getActiveSheet();
        $highestRow = $objWorksheet->getHighestRow();
        //获取excel数据
        $needList = [];
        for ($row = 12; $row <= $highestRow; $row++) {
            $tradeNo = $objWorksheet->getCell('C' . $row)->getValue();
            $tradeTime = $objWorksheet->getCell('B' . $row)->getValue();
            $need = [];
            $qty = $objWorksheet->getCell('L' . $row)->getValue();//商品数量
            $need['itemcode'] = $objWorksheet->getCell('F' . $row)->getValue();//商品编号
            $need['originalamount'] = floatval($objWorksheet->getCell('I' . $row)->getValue());//原价
            $need['qty'] = (int)$qty;
            if ($need['qty'] == 0) {
                continue;
            }
            $need['originalamount'] = $need['originalamount'] * $need['qty'];
            $need['netamount'] = floatval($objWorksheet->getCell('M' . $row)->getValue());//付款金额
            $needList[$tradeNo]['itemList'][] = $need;
            $needList[$tradeNo]['tradeTime'] = $tradeTime;
            $needList[$tradeNo]['tradeNo'] = $tradeNo;
        }
        if (count($needList) == 0) {
            $this->printHandel('文档记录为空！');
            return;
        }

        if ($this->platform['ftp_need'] == 1) {
            $this->ftpHandle($needList);
        }
        if ($this->platform['wsdl_need'] == 1) {
            switch ($this->platform['type_code']) {
                case 'shyfc':
                    $this->processData($needList);
                    break;
                default:
                    $this->wsdlHandle($needList);

            }
        }

    }


    private function ftpHandle($needList)
    {
        $this->printHandel('开始FTP文件处理...');
        $filename = $this->platformFtpConf['file_name'];
        $filename = str_replace('{ar}', $this->platformFtpConf['ar'], $filename);
        if (strpos($filename, '{time') !== false) {
            preg_match_all('/\{time\:(.*?)\}/', $filename, $matches);
            foreach ($matches[0] as $key => $match) {
                $filename = str_replace($match, date($matches[1][$key]), $filename);
            }
            unset($matches);
        }
        $log = new Log();
        $log->path = APP_PATH . 'FtpFile/' . $this->platform['type_code'] . '/' . $filename;
        foreach ($needList as $tradeNo => $need) {
            $totalOriginalAmount = 0;
            $totalQty = 0;
            $totalNetAmount = 0;
            $i = 1;
            foreach ($need['itemList'] as $key => $item) {
                if ($item['qty'] == 0) {
                    unset($need['itemList'][$key]);
                    continue;
                }
                $totalOriginalAmount += $item['originalamount'];
                $totalQty += $item['qty'];
                $totalNetAmount += $item['netamount'];
                $i++;
            }
            if (count($need['itemList']) == 0) {
                continue;
            }
            $content = $this->platformFtpConf['field_list'];
            $content = str_replace('{ar}', $this->platformFtpConf['ar'], $content);
            $content = str_replace('{tillid}', $this->platformFtpConf['tillid'], $content);
            if (strpos($content, '{time') !== false) {
                preg_match_all('/\{time\:(.*?)\}/', $content, $matches);
                foreach ($matches[0] as $key => $match) {
                    $content = str_replace($match, date($matches[1][$key]), $content);
                }
            }
            $content = str_replace('{tradeno}', $tradeNo, $content);
            $content = str_replace('{discountamount}', $totalOriginalAmount - $totalNetAmount, $content);
            $content = str_replace('{netamount}', $totalNetAmount, $content);
            $content = str_replace('{is_refund}', $totalQty > 0 ? 0 : 1, $content);

            $log->saveLog($content);

        }
        $this->printHandel('FTP文件处理完成');
        $config = [
            'host' => $this->platformFtpConf['host'], //服务器
            'port' => $this->platformFtpConf['port'], //端口
            'timeout' => 90, //超时时间
            'username' => $this->platformFtpConf['username'], //用户名
            'password' => $this->platformFtpConf['password'], //密码
        ];
        $ftp = new Ftp($config);
        $ftpFile = [
            'savepath' => '/',
            'savename' => $filename,
            'tmp_name' => $log->path
        ];
        $this->printHandel('准备上传FTP文件...');
        $result = $ftp->save($ftpFile);
        if (!$result) {
            $this->printHandel('FTP文件上传失败');
        }
        $this->printHandel('FTP文件上传成功');
    }

    private function wsdlHandle($needList)
    {
        $this->printHandel('准备接口数据处理...');
        $params = [];
        $params['header'] = [
            'licensekey' => '',
            'username' => $this->platformWsdlConf['username'],
            'password' => $this->platformWsdlConf['password'],
            'lang' => '',
            'pagerecords' => 1,
            'pageno' => 1,
            'updatecount' => 1,
            'messagetype' => 'SALESDATA',
            'messageid' => '332',
            'version' => 'V332M',
        ];
        $paramsList = [];
        foreach ($needList as $tradeNo => $need) {
            $totalOriginalAmount = 0;
            $totalQty = 0;
            $totalNetAmount = 0;
            $itemList = [];
            $i = 1;
            foreach ($need['itemList'] as $item) {
                if ($item['qty'] == 0) {
                    continue;
                }
                $totalOriginalAmount += $item['originalamount'];
                $totalQty += $item['qty'];
                $totalNetAmount += $item['netamount'];
                $itemList[] = [
                    'itemcode' => $this->platformWsdlConf['itemcode'],
                    'lineno' => $i,
                    'invttype' => 0,
                    'qty' => $item['qty'],
                    'netamount' => $item['netamount'],
                    'originalprice' => $item['originalamount'],
                    'sellingprice' => $item['netamount'],
                    'vipdiscountpercent' => 1,
                    'vipdiscountless' => 0,
                    'totaldiscountless1' => 0,
                    'totaldiscountless2' => 0,
                    'totaldiscountless' => 0,
                    'bonusearn' => 0,
                    'exstk2sales' => 0,
                ];
                $i++;
            }
            if (count($itemList) == 0) {
                continue;
            }

            $tradeTime = strtotime($need['tradeTime']);
            $params['salestotal'] = [
                'txdate_yyyymmdd' => date('Ymd', $tradeTime),
                'txtime_hhmmss' => date('His', $tradeTime),
                'mallid' => $this->platformWsdlConf['mallid'],
                'storecode' => $this->platformWsdlConf['storecode'],
                'tillid' => $this->platformWsdlConf['tillid'],
                'txdocno' => $tradeNo,
                'netqty' => $totalQty,
                'originalamount' => $totalOriginalAmount,
                'sellingamount' => $totalNetAmount,
                'couponqty' => 0,
                'netamount' => $totalNetAmount,
                'paidamount' => $totalNetAmount,
                'ttltaxamount1' => 0,
                'ttltaxamount2' => 0,
                'changeamount' => 0,
                'cashier' => '1022',
                'salesman' => '1022',
            ];
            $params['salesitems'] = $itemList;
            $params['salestenders'] = [
                [
                    'lineno' => 1,
                    'tendertype' => 0,
                    'tendercategory' => 0,
                    'tendercode' => 'CH',
                    'payamount' => $totalNetAmount,
                    'baseamount' => $totalNetAmount,
                    'excessamount' => 0,
                ]
            ];
            $paramsList[] = $params;
        }
        $this->printHandel('接口数据处理完成');
        foreach ($paramsList as $params) {
            $this->printHandel('请求接口,交易单号:' . $params['salestotal']['txdocno'] . ' ...');
            $result = $this->createRequest('postsalescreate', ['astr_request' => $params]);
            if (!$result) {
                $this->printHandel('交易单号:' . $params['salestotal']['txdocno'] . ' 请求异常！');
                return;
            }
            if ($result['postsalescreateResult']['header']['responsecode'] == 0) {
                $this->printHandel('交易单号:' . $params['salestotal']['txdocno'] . ' 请求成功！');
            } else {
                $this->printHandel('交易单号:' . $params['salestotal']['txdocno'] . ' 请求失败。错误信息:' . $result['postsalescreateResult']['header']['responsemessage']);
            }
        }
    }


    /**
     * 怡丰城 shyfc
     * @param $needList
     */
    private function processData($needList)
    {
        $params = [
            'userid' => $this->platformWsdlConf['username'],
            'password' => $this->platformWsdlConf['password'],
            'cmdid' => '2000',
        ];
        $inputparas = [];
        foreach ($needList as $tradeNo => $need) {
            $tradeTime = strtotime($need['tradeTime']);
            $totalOriginalAmount = 0;
            $totalQty = 0;
            $totalNetAmount = 0;
            $itemList = [];
            foreach ($need['itemList'] as $item) {
                if ($item['qty'] == 0) {
                    continue;
                }
                $totalOriginalAmount += $item['originalamount'];
                $totalQty += $item['qty'];
                $totalNetAmount += $item['netamount'];
                $itemList[] = $item;
            }
            if (count($itemList) == 0) {
                continue;
            }

            $inputpara = [
                '门店编号' => $this->platformWsdlConf['mallid'],
                '店铺编号' => $this->platformWsdlConf['storecode'],
                '收银机号' => $this->platformWsdlConf['tillid'],
                '交易流水号' => $tradeNo,
                '交易时间' => date('Y-m-d H:i:s', $tradeTime),
                '交易类型' => $totalQty > 0 ? 1 : 3,
                '商品代码' => $this->platformWsdlConf['itemcode'],
                '价格' => $totalNetAmount,
                '数量' => $totalQty,
                '金额' => $totalNetAmount,
                '付款方式' => '',
                '原交易收银机号' => '',
                '原交易流水号' => '',
                '现金01' => $totalNetAmount,
                '银行卡0301' => 0,
                '第三方支付0514' => 0,
                '电子券0500' => 0,
                '其它0705' => 0,
                '电子类方式0513' => 0,
                '非电子券0501' => 0,
                '储值卡（南海）' => 0,
                '会员卡号' => '',
                '电子券号' => 0
            ];
            $inputparas[$tradeNo] = implode(',', $inputpara);
        }
        if (!count($inputparas)) {
            $this->printHandel('当前数据为空');
        }
        $params['rtn'] = 0;
        foreach ($inputparas as $tradeNo => $inputpara) {
            $params['inputpara'] = $inputpara;
            $result = $this->createRequest('processdata', $params);
            if ($result['rtn'] >= 0) {
                $this->printHandel('交易单号:' . $tradeNo . ' 请求接口成功');
            } else {
                $this->printHandel('交易单号:' . $tradeNo . ' 请求接口失败，返回值rtn【' . $result['rtn'] . '】' . ' 错误信息：' . $result['errormsg']);
            }
            sleep(1);
        }

    }

}

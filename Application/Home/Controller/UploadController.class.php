<?php

namespace Home\Controller;


use Common\Common\Log;
use Common\Model\UploadFtpModel;
use Common\Model\UploadModel;
use Common\Model\UploadWsdlModel;
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
                $uploadId = $uploadModel->add($uploadModel->data());
                $upload['id'] = $uploadId;
                $this->upload = $upload;
            }

            $this->dataHandle();

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

    public function wsdlList()
    {
        if (!isset($_GET['id']) || !$_GET['id']) {
            $this->error('参数有误');
        }
        $uploadModel = new UploadModel();
        $where = [];
        if (!$this->user['is_admin']) {
            $where = ['platform_id' => $this->platform['platform_id']];
        }
        $where['id'] = $_GET['id'];
        $upload = $uploadModel->where($where)->find();
        if (!$upload) {
            $this->error('参数有误');
        }
        $uploadWsdlList = (new UploadWsdlModel())->where(['upload_id' => $upload['id']])->select();
        $this->assign('list', $uploadWsdlList);
        $this->display();

    }

    public function delete()
    {
        if (!isset($_GET['id']) || !$_GET['id']) {
            $this->error('参数有误！');
        }
        $uploadModel = new UploadModel();
        $upload = $uploadModel->where(['id' => $_GET['id']])->find();
        if ($upload['status'] != 0) {
            $this->error('参数有误！');
        }
        if ($this->user['is_admin'] != 1) {
            if ($upload['user_id'] != $this->user['user_id']) {
                $this->error('参数有误！');
            }
        }
        $this->getPlatformData($upload['platform_id']);
        unlink(APP_PATH . 'Upload/' . $this->platform['type_code'] . '/' . $upload['save_name']);
        $uploadModel->where(['id' => $_GET['id']])->delete();
        $uploadFtpModel = new UploadFtpModel();
        $uploadFtp = $uploadFtpModel->where(['upload_id' => $upload['id']])->find();
        if ($uploadFtp) {
            $uploadFtpModel->where(['upload_id' => $upload['id']])->delete();
            $file_name = APP_PATH . 'FtpFile/' . $this->platform['type_code'] . '/' . $uploadFtp['filename'];
            unlink($file_name);
        }
        $uploadWsdlModel = new UploadWsdlModel();
        $uploadWsdlModel->where(['upload_id' => $upload['id']])->delete();
        $this->redirect('index');

    }

    public function seeLog()
    {
        if (!isset($_GET['id']) || !$_GET['id']) {
            $this->error('参数有误！');
        }
        $uploadModel = new UploadModel();
        $upload = $uploadModel->where(['id' => $_GET['id']])->find();
        if ($upload['status'] == 0) {
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
        if (!$this->upload || $this->upload['status'] != 0) {
            $this->printHandel('参数有误！');
            return;
        }
        if (!$this->user['is_admin']) {
            if ($this->upload['user_id'] != $this->user['user_id']) {
                $this->printHandel('参数有误！');
                return;
            }
        }
        $this->getPlatformData($this->upload['platform_id']);
        if ($this->platform['ftp_need'] == 1) {
            $this->ftpUpload();
        }
        if ($this->platform['wsdl_need'] == 1) {
            switch ($this->platform['type_code']) {
                case 'shyfc':
                    $this->shyfcUpload();
                    break;
                case 'cshyd':
                    $this->cshydUpload();
                    break;
                default:
                    $this->wsdlUpload();

            }
        }
        $this->printHandel('上传完成！');
        $this->upload['status'] = $this->errorCode == 0 ? 1 : 2;
        $this->upload['log_data'] = $this->printMessage;
        $uploadModel->create($this->upload);
        $uploadModel->save($uploadModel->data());
    }


    public function completeOne()
    {
        ob_clean();
        if (!isset($_GET['id']) || !$_GET['id']) {
            $this->printHandel('参数有误！');
            return;
        }
        $uploadWsdlModel = new UploadWsdlModel();
        $uploadWsdl = $uploadWsdlModel->where(['id' => $_GET['id']])->find();

        if (!$uploadWsdl || $uploadWsdl['status'] == 1) {
            $this->printHandel('参数有误！');
            return;
        }

        $uploadModel = new UploadModel();
        $upload = $uploadModel->where(['id' => $uploadWsdl['upload_id']])->find();
        if (!$upload) {
            $this->printHandel('参数有误！');
            return;
        }
        if (!$this->user['is_admin']) {
            if ($this->upload['user_id'] != $this->user['user_id']) {
                $this->printHandel('参数有误！');
                return;
            }
        }
        $this->getPlatformData($upload['platform_id']);
        $this->printHandel('请求接口,交易单号:' . $uploadWsdl['trade_no'] . ' ...');
        switch ($this->platform['type_code']) {
            case 'shyfc':
                $result = $this->createRequest('processdata', json_decode($uploadWsdl['request_data'], true));
                if (!$result) {
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求异常！');
                    return;
                }
                if ($result['rtn'] >= 0) {
                    $uploadWsdl['status'] = 1;
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口成功');
                } else {
                    $this->errorCode = 1;
                    $uploadWsdl['status'] = 2;
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口失败，返回值rtn【' . $result['rtn'] . '】' . ' 错误信息：' . $result['errormsg']);
                }
                break;
            case 'cshyd':
                $result = $this->createRequest('PostSales', json_decode($uploadWsdl['request_data'], true));
                if (!$result) {
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求异常！');
                    return;
                }
                $rsArray = xml2array($result['PostSalesResult']['any']);

                if ($rsArray['Response']['Result']['ErrorCode'] == 0 || $rsArray['Response']['Result']['ErrorCode'] == -100) {
                    $uploadWsdl['status'] = 1;
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口成功');
                } else {
                    $this->errorCode = 1;
                    $uploadWsdl['status'] = 2;
                    if (is_array($rsArray['Response']['Result']['ErrorMessage'])) {
                        $error_message = json_encode($rsArray['Response']['Result']['ErrorMessage']);
                    } else {
                        $error_message = $rsArray['Response']['Result']['ErrorMessage'];
                    }
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口失败，返回值【' . $rsArray['Response']['Result']['ErrorCode'] . '】' . ' 错误信息：' . $error_message);
                }
                $result = $rsArray;
                break;
            default:
                $result = $this->createRequest('postsalescreate', json_decode($uploadWsdl['request_data'], true));
                if (!$result) {
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求异常！');
                    return;
                }

                if ($result['postsalescreateResult']['header']['responsecode'] == 0 || $result['postsalescreateResult']['header']['responsecode'] == -100) {
                    $uploadWsdl['status'] = 1;
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求成功！');
                } else {
                    $this->errorCode = 1;
                    $uploadWsdl['status'] = 2;
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求失败。错误信息:' . $result['postsalescreateResult']['header']['responsemessage']);
                }

        }
        $uploadWsdl['response_data'] = json_encode($result);
        $uploadWsdlModel->create($uploadWsdl);
        $uploadWsdlModel->save($uploadWsdlModel->data());
        $uploadWsdl = $uploadWsdlModel->where('status !=1 and upload_id=' . $upload['id'])->find();
        if (!$uploadWsdl) {
            $upload['status'] = 1;
            $uploadModel->create($this->upload);
            $uploadModel->save($uploadModel->data());
        }

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
        $reader = null;
        try {
            $reader = \PHPExcel_IOFactory::createReader($renderType);
        } catch (Exception $e) {
            $this->error('Excel文件有误！');
        }
        $file_name = APP_PATH . 'Upload/' . $this->platform['type_code'] . '/' . $this->upload['save_name'];
        $PHPExcel = $reader->load($file_name); // 文档名称
        $objWorksheet = $PHPExcel->getActiveSheet();
        $highestRow = $objWorksheet->getHighestRow();
        //获取excel数据
        $needList = [];
        for ($row = 12; $row <= $highestRow; $row++) {
            $tradeNo = $objWorksheet->getCell('C' . $row)->getValue();
            if (!$this->regularAscii($tradeNo)) {
                $this->error('【C 列】单据单号 不能是中文');
            }
            $tradeTime = $objWorksheet->getCell('B' . $row)->getValue();
            $tradeTime = strtotime($tradeTime);
            if (!$tradeTime) {
                $this->error('【B 列】交易时间 格式有误（格式为：2017/11/8 14:36:53）');
            }
            $need = [];
            $qty = $objWorksheet->getCell('L' . $row)->getValue();//商品数量
            if (!is_numeric($qty)) {
                $this->error('【L 列】商品数量必需是整数！');
            }
            $need['itemcode'] = $objWorksheet->getCell('F' . $row)->getValue();//商品编号

            $need['originalamount'] = floatval($objWorksheet->getCell('I' . $row)->getValue());//原价
            if (!is_numeric($need['originalamount'])) {
                $this->error('【I 列】原价必需是数字！');
            }
            $need['unitamount'] = floatval($objWorksheet->getCell('K' . $row)->getValue());//原价
            if (!is_numeric($need['originalamount'])) {
                $this->error('【K 列】单价必需是数字！');
            }
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
            $this->error('文档记录为空！');
        }
        iconv("ASCII", "UTF-8//IGNORE", 9);
        if ($this->platform['ftp_need'] == 1) {
            $this->ftpDataHandle($needList);
        }
        if ($this->platform['wsdl_need'] == 1) {
            switch ($this->platform['type_code']) {
                case 'shyfc':
                    $this->shyfcDataHandle($needList);
                    break;
                case 'cshyd':
                    $this->cshydDataHandle($needList);
                    break;
                default:
                    $this->wsdlDataHandle($needList);

            }
        }

    }

    private function ftpDataHandle($needList)
    {
        $firstData = '';
        $filename = '';
        $log = new Log();
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
                    $content = str_replace($match, date($matches[1][$key], $need['tradeTime']), $content);
                }
            }
            if (strpos($content, '{chr(') !== false) {
                preg_match_all('/\{chr\((.*?)\)\}/', $content, $matches);
                foreach ($matches[0] as $key => $match) {
                    $content = str_replace($match, chr($matches[1][$key]), $content);
                }
            }
            if (strpos($content, '{tradekey(') !== false) {
                preg_match_all('/\{tradekey\((.*?)\)\}/', $content, $matches);
                foreach ($matches[0] as $key => $match) {
                    $content = str_replace($match, str_pad($this->getTradeKey(), $matches[1][$key], '0', STR_PAD_LEFT), $content);
                }
            }
            $content = str_replace('{tradeno}', $tradeNo, $content);
            $content = str_replace('{discountamount}', $totalOriginalAmount - $totalNetAmount, $content);
            $content = str_replace('{netamount}', $totalNetAmount, $content);
            $content = str_replace('{is_refund}', $totalNetAmount > 0 ? 0 : 1, $content);
            if ($firstData == '') {
                $firstData = $need['tradeTime'];
                $filename = $this->platformFtpConf['file_name'];
                $filename = str_replace('{ar}', $this->platformFtpConf['ar'], $filename);
                if (strpos($filename, '{time') !== false) {
                    preg_match_all('/\{time\:(.*?)\}/', $filename, $matches);
                    foreach ($matches[0] as $key => $match) {
                        $filename = str_replace($match, date($matches[1][$key], $firstData), $filename);
                    }
                    unset($matches);
                }
                $log->path = APP_PATH . 'FtpFile/' . $this->platform['type_code'] . '/' . $filename . '.' . $this->upload['id'];
            }

            $log->saveLog($content);
        }
        $uploadFtpModel = new UploadFtpModel();
        $uploadFtp = $uploadFtpModel->where(['upload_id' => $this->upload['id']])->find();
        if ($uploadFtp) {
            $this->error('已经存在ftp文件');
        }
        $uploadFtpModel->create([
            'status' => 0,
            'upload_id' => $this->upload['id'],
            'filename' => $filename . '.' . $this->upload['id'],
        ]);
        $uploadFtpModel->add($uploadFtpModel->data());
    }


    private function ftpUpload()
    {
        $uploadFtpModel = new UploadFtpModel();
        $uploadFtp = $uploadFtpModel->where(['upload_id' => $this->upload['id']])->find();
        $filename = $uploadFtp['filename'];
        $log = new Log();
        $log->path = APP_PATH . 'FtpFile/' . $this->platform['type_code'] . '/' . $filename;
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
            'savename' => str_replace('.' . $this->upload['id'], '', $filename),
            'tmp_name' => realpath($log->path)
        ];
        $this->printHandel('准备上传FTP文件...');
        $result = $ftp->save($ftpFile);
        if (!$result) {
            $this->errorCode = 1;
            $this->printHandel('FTP文件上传失败 ' . $ftp->getError());
        } else {
            $this->printHandel('FTP文件上传成功');

        }
    }

    private function wsdlDataHandle($needList)
    {
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
            if ($totalNetAmount == 0) {
                continue;
            }

            $params['salestotal'] = [
                'txdate_yyyymmdd' => date('Ymd', $need['tradeTime']),
                'txtime_hhmmss' => date('His', $need['tradeTime']),
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

            //已存在就不进行请求
            $uploadWsdlModel = new UploadWsdlModel();
            $uploadWsdl = $uploadWsdlModel->where(['trade_no' => $tradeNo])->find();
            if ($uploadWsdl) {
                continue;
            }
            $uploadWsdlModel->create([
                'upload_id' => $this->upload['id'],
                'trade_no' => $tradeNo,
                'netamount' => $totalNetAmount,
                'request_data' => json_encode(['astr_request' => $params]),
                'qty' => $totalQty,
                'status' => 0
            ]);
            $uploadWsdlModel->add($uploadWsdlModel->data());
        }
    }

    private function wsdlUpload()
    {
        $uploadWsdlModel = new UploadWsdlModel();
        $uploadWsdlList = $uploadWsdlModel->where(['upload_id' => $this->upload['id']])->where('status!=1')->select();
        foreach ($uploadWsdlList as $uploadWsdl) {
            $this->printHandel('请求接口,交易单号:' . $uploadWsdl['trade_no'] . ' ...');
            $result = $this->createRequest('postsalescreate', json_decode($uploadWsdl['request_data'], true));
            if (!$result) {
                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求异常！');
                return;
            }

            if ($result['postsalescreateResult']['header']['responsecode'] == 0 || $result['postsalescreateResult']['header']['responsecode'] == -100) {
                $uploadWsdl['status'] = 1;
                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求成功！');
            } else {
                $this->errorCode = 1;
                $uploadWsdl['status'] = 2;

                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求失败。错误信息:' . $result['postsalescreateResult']['header']['responsemessage']);
            }
            $uploadWsdl['response_data'] = json_encode($result);
            $uploadWsdlModel->create($uploadWsdl);
            $uploadWsdlModel->save($uploadWsdlModel->data());
        }
    }


    /**
     * 上海怡丰城数据处理 shyfc
     * @param $needList
     */
    private function shyfcDataHandle($needList)
    {
        $params = [
            'userid' => $this->platformWsdlConf['username'],
            'password' => $this->platformWsdlConf['password'],
            'cmdid' => '2000',
        ];
        foreach ($needList as $tradeNo => $need) {
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
            if ($totalNetAmount == 0) {
                continue;
            }

            $inputpara = [
                '门店编号' => $this->platformWsdlConf['mallid'],
                '店铺编号' => $this->platformWsdlConf['storecode'],
                '收银机号' => $this->platformWsdlConf['tillid'],
                '交易流水号' => $tradeNo,
                '交易时间' => date('Y-m-d H:i:s', $need['tradeTime']),
                '交易类型' => $totalNetAmount > 0 ? 1 : 3,
                '商品代码' => $this->platformWsdlConf['itemcode'],
                '价格' => $totalNetAmount,
                '数量' => 1,
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
            $params['inputpara'] = implode(',', $inputpara);
            $params['rtn'] = 0;
            $uploadWsdlModel = new UploadWsdlModel();
            $uploadWsdl = $uploadWsdlModel->where(['trade_no' => $tradeNo])->find();
            if ($uploadWsdl) {
                continue;
            }
            $uploadWsdlModel->create([
                'upload_id' => $this->upload['id'],
                'trade_no' => $tradeNo,
                'netamount' => $totalNetAmount,
                'request_data' => json_encode($params),
                'qty' => 1,
                'status' => 0
            ]);
            $uploadWsdlModel->add($uploadWsdlModel->data());
        }
    }

    /**
     * 长沙环宇店数据处理 cshyd
     * @param $needList
     */
    private function cshydDataHandle($needList)
    {
        $params = [
            'strCallUserCode' => $this->platformWsdlConf['username'],
            'strCallPassword' => $this->platformWsdlConf['password'],
            'strStoreCode' => $this->platformWsdlConf['storecode'],
        ];
        foreach ($needList as $tradeNo => $need) {
            $totalOriginalAmount = 0;
            $totalQty = 0;
            $totalNetAmount = 0;
            foreach ($need['itemList'] as $item) {
                if ($item['qty'] == 0) {
                    continue;
                }
                $totalOriginalAmount += $item['originalamount'];
                $totalQty += $item['qty'];
                $totalNetAmount += $item['netamount'];
            }
            $params['strType'] = $totalNetAmount > 0 ? 'SA' : 'SR';
            $params['strSalesDate_YYYYMMDD'] = date('Ymd', $need['tradeTime']);
            $params['strSalesTime_HHMISS'] = date('His', $need['tradeTime']);
            $params['strSalesDocNo'] = $tradeNo;
            $params['strTenderCode'] = sprintf('{CH,%s,0,0}', $totalNetAmount);
            $params['strItems'] = sprintf('{%s,%s,%s}', $this->platformWsdlConf['itemcode'], $totalNetAmount > 0 ? 1 : -1, $totalNetAmount);
            $uploadWsdlModel = new UploadWsdlModel();
            $uploadWsdl = $uploadWsdlModel->where(['trade_no' => $tradeNo])->find();
            if ($uploadWsdl) {
                continue;
            }
            $uploadWsdlModel->create([
                'upload_id' => $this->upload['id'],
                'trade_no' => $tradeNo,
                'netamount' => $totalNetAmount,
                'request_data' => json_encode($params),
                'qty' => $totalQty,
                'status' => 0
            ]);
            $uploadWsdlModel->add($uploadWsdlModel->data());
        }
    }


    /**
     * 怡丰城上传 shyfc
     */
    private function shyfcUpload()
    {
        $uploadWsdlModel = new UploadWsdlModel();
        $uploadWsdlList = $uploadWsdlModel->where(['upload_id' => $this->upload['id']])->where('status!=1')->select();
        foreach ($uploadWsdlList as $uploadWsdl) {
            $this->printHandel('请求接口,交易单号:' . $uploadWsdl['trade_no'] . ' ...');
            $result = $this->createRequest('processdata', json_decode($uploadWsdl['request_data'], true));
            if (!$result) {
                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求异常！');
                return;
            }
            if ($result['rtn'] >= 0) {
                $uploadWsdl['status'] = 1;
                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口成功');
            } else {
                $this->errorCode = 1;
                $uploadWsdl['status'] = 2;
                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口失败，返回值rtn【' . $result['rtn'] . '】' . ' 错误信息：' . $result['errormsg']);
            }
            $uploadWsdl['response_data'] = json_encode($result);
            $uploadWsdlModel->create($uploadWsdl);
            $uploadWsdlModel->save($uploadWsdlModel->data());
        }
    }

    /**
     * 长沙环宇店上传 cshyd
     */
    private function cshydUpload()
    {
        $uploadWsdlModel = new UploadWsdlModel();
        $uploadWsdlList = $uploadWsdlModel->where(['upload_id' => $this->upload['id']])->where('status!=1')->select();
        foreach ($uploadWsdlList as $uploadWsdl) {
            $this->printHandel('请求接口,交易单号:' . $uploadWsdl['trade_no'] . ' ...');
            $result = $this->createRequest('PostSales', json_decode($uploadWsdl['request_data'], true));
            if (!$result) {
                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求异常！');
                return;
            }
            $rsArray = xml2array($result['PostSalesResult']['any']);

            if ($rsArray['Response']['Result']['ErrorCode'] == 0) {
                $uploadWsdl['status'] = 1;
                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口成功');
            } else {
                $this->errorCode = 1;
                $uploadWsdl['status'] = 2;
                if (is_array($rsArray['Response']['Result']['ErrorMessage'])) {
                    $error_message = json_encode($rsArray['Response']['Result']['ErrorMessage']);
                } else {
                    $error_message = $rsArray['Response']['Result']['ErrorMessage'];
                }
                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口失败，返回值【' . $rsArray['Response']['Result']['ErrorCode'] . '】' . ' 错误信息：' . $error_message);
            }
            $uploadWsdl['response_data'] = json_encode($rsArray);
            $uploadWsdlModel->create($uploadWsdl);
            $uploadWsdlModel->save($uploadWsdlModel->data());
        }
    }

}

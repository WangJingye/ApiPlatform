<?php

namespace Home\Controller;


use Common\Common\Log;
use Common\Model\UploadFtpModel;
use Common\Model\UploadModel;
use Common\Model\UploadWsdlModel;
use Think\Exception;
use Think\Model;
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
            $model = new Model();
            try {
                $model->startTrans();
                $ext = end(explode('.', $_FILES['file']['name']));
                if ($ext != 'xlsx' && $ext != 'xls' && $ext != 'csv') {
                    throw new \Exception('请上传Excel的文档！');
                }
                $platform_id = $this->platform['platform_id'];
                if ($this->user['is_admin']) {
                    if (!isset($_POST['platform_id']) || !$_POST['platform_id']) {
                        throw new \Exception('请选择店铺');
                    }
                    $platform_id = $_POST['platform_id'];
                }
                $this->getPlatformData($platform_id);
                $uploadModel = new UploadModel();
                $upload = $uploadModel->where(['platform_id' => $platform_id, 'status' => 0])->find();
                if ($upload) {
                    throw new \Exception('有未完成的单据记录，请完成后再上传');
                }
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
                if ($ext != 'csv' && $this->platform['type_code'] == 'lnfyd') {
                    $this->lnfydDataHandle();
                } else {
                    $this->dataHandle();
                }
                $model->commit();
            } catch (\Exception $e) {
                $model->rollback();
                $this->error($e->getMessage());
            }
            $this->redirect('index');
        }
        $this->display();
    }

    public function index()
    {
        $perPage = 20;
        $page = 1;
        if (isset($_GET['page']) && $_GET['page']) {
            $page = (int)$_GET['page'];
        }
        if ($page < 1) {
            $page = 1;
        }
        $uploadModel = new UploadModel();
        $where = [];
        if (!$this->user['is_admin']) {
            $where['a.platform_id'] = $this->platform['platform_id'];
        }
        $totalNumber = $uploadModel->table('upload as a')->join('left join platform as b on b.platform_id=a.platform_id')->where($where)->count();
        $totalPage = ceil($totalNumber / $perPage);
        if ($page > $totalPage) {
            $page = $totalPage;
        }

        if ($page < 1) {
            $page = 1;
        }
        $selector = $uploadModel->table('upload as a')->join('left join platform as b on b.platform_id=a.platform_id')->where($where);
        $list = $selector->field('a.id,b.platform_name,a.file_name,a.status,a.create_time,b.ftp_need,b.wsdl_need')->order('a.create_time desc')
            ->limit(($perPage * ($page - 1)) . ',' . $perPage)->select();

        $this->assign('list', $list);
        $this->assign('total_page', $totalPage);
        $this->assign('current_page', $page);
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
        if ($this->platform['wsdl_need']) {
            $uploadWsdlModel = new UploadWsdlModel();
            $uploadWsdlModel->where(['upload_id' => $upload['id']])->where('status=0')->delete();
        }

        if ($this->platform['wsdl_need']) {
            $wsdlList = $uploadWsdlModel->where(['upload_id' => $upload['id']])->where('status!=0')->select();
            if (!count($wsdlList)) {
                $uploadWsdlModel = new UploadWsdlModel();
                $uploadWsdlModel->where(['upload_id' => $upload['id']])->where('status=0')->delete();
                unlink(APP_PATH . 'Upload/' . $this->platform['type_code'] . '/' . $upload['save_name']);
                $uploadModel->where(['id' => $_GET['id']])->delete();
            }else{
                $this->error('已执行的记录不允许删除！');
            }
        } else if ($this->platform['ftp']) {
            $uploadFtpModel = new UploadFtpModel();
            $uploadFtp = $uploadFtpModel->where(['upload_id' => $upload['id']])->find();
            if ($uploadFtp) {
                $uploadFtpModel->where(['upload_id' => $upload['id']])->delete();
                $file_name = APP_PATH . 'FtpFile/' . $this->platform['type_code'] . '/' . $uploadFtp['filename'];
                unlink($file_name);
            }
            unlink(APP_PATH . 'Upload/' . $this->platform['type_code'] . '/' . $upload['save_name']);
            $uploadModel->where(['id' => $_GET['id']])->delete();
        }


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
        set_time_limit(0);
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
            $this->ftpSync();
        }
        if ($this->platform['wsdl_need'] == 1) {
            switch ($this->platform['type_code']) {
                case 'shyfc':
                    $this->shyfcSync();
                    break;
                case 'xtdyc':
                    $this->xtdycSync();
                    break;
                case 'asq':
                    $this->asqSync();
                    break;
                default:
                    $this->wsdlSync();

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
                } else {
                    if ($result['rtn'] >= 0) {
                        $uploadWsdl['status'] = 1;
                        $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口成功');
                    } else {
                        $this->errorCode = 1;
                        $uploadWsdl['status'] = 2;
                        $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口失败，返回值rtn【' . $result['rtn'] . '】' . ' 错误信息：' . $result['errormsg']);
                    }
                }
                break;
            case 'xtdyc':
                $result = $this->createRequest('PostSales', json_decode($uploadWsdl['request_data'], true));
                if (!$result) {
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求异常！');
                } else {
                    $rsArray = xml2array($result['PostSalesResult']['any']);

                    if ($rsArray['Response']['Result']['ErrorCode'] == 0 || $rsArray['Response']['Result']['ErrorCode'] == -100 || (strpos($result['postsalescreateResult']['header']['responsemessage'], 'exists') !== false)) {
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
                }
                break;
            case 'asq':
                $result = $this->createRequest('TrasAdd', json_decode($uploadWsdl['request_data'], true));
                if (!$result) {
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求异常！');
                    return;
                }
                if ($result['TrasAddResult']) {
                    $uploadWsdl['status'] = 1;
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口成功');
                } else {
                    $this->errorCode = 1;
                    $uploadWsdl['status'] = 2;
                    $error_message = json_encode($result);
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口失败，返回值【' . $result['Response']['Result']['ErrorCode'] . '】' . ' 错误信息：' . $error_message);
                }
                break;
            default:
                $result = $this->createRequest('postsalescreate', json_decode($uploadWsdl['request_data'], true));
                if (!$result) {
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求异常！');
                } else {
                    if ($result['postsalescreateResult']['header']['responsecode'] == 0 || $result['postsalescreateResult']['header']['responsecode'] == -100 || (strpos($result['postsalescreateResult']['header']['responsemessage'], 'exists') !== false)) {
                        $uploadWsdl['status'] = 1;
                        $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求成功！');
                    } else {
                        $this->errorCode = 1;
                        $uploadWsdl['status'] = 2;
                        $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求失败。错误信息:' . $result['postsalescreateResult']['header']['responsemessage']);
                    }
                }
        }
        $uploadWsdl['response_data'] = json_encode($result);
        $uploadWsdlModel->create($uploadWsdl);
        $uploadWsdlModel->save($uploadWsdlModel->data());
        $uploadWsdl = (new UploadWsdlModel())->where(['upload_id' => $upload['id'], 'status' => ['neq', '1']])->find();
        if (!$uploadWsdl) {
            $upload['status'] = '1';
            $uploadModel->create($upload);
            $uploadModel->save($uploadModel->data());
        }

    }

    /**
     * @throws \PHPExcel_Exception
     * @throws \PHPExcel_Reader_Exception
     * @throws \Think\Exception
     */
    private function dataHandle()
    {
        $ext = end(explode('.', $this->upload['save_name']));
        if ($ext == 'csv') {
            $needList = $this->parseCSV();
        } else {
            $needList = $this->parseXLS();
        }

        if (count($needList) == 0) {
            throw new \Exception('文档记录为空！');
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
                case 'xtdyc':
                    $this->xtdycDataHandle($needList);
                    break;
                case 'asq':
                    $this->asqDataHandle($needList);
                    break;
                default:
                    $this->wsdlDataHandle($needList);

            }
        }

    }

    /**
     * @return array
     * @throws \PHPExcel_Exception
     * @throws \PHPExcel_Reader_Exception
     * @throws \Think\Exception
     */
    private function parseXLS()
    {
        $ext = end(explode('.', $this->upload['save_name']));
        if ($ext == 'xlsx') {
            $renderType = 'Excel2007';
        } else if ($ext == 'xls') {
            $renderType = 'Excel5';
        }
        $this->getPlatformData($this->upload['platform_id']);
        import("Org.Util.PHPExcel");

        import("Org.Util.PHPExcel.IOFactory");
        $reader = null;
        try {
            $reader = \PHPExcel_IOFactory::createReader($renderType);
        } catch (\Exception $e) {
            throw new \Exception('Excel文件有误！');
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
                throw new \Exception('【C 列】单据单号 不能是中文');
            }
            $tradeTime = $objWorksheet->getCell('B' . $row)->getValue();
            $tradeTime = strtotime($tradeTime);
            if (!$tradeTime) {
                throw new \Exception('【B 列】交易时间 格式有误（格式为：2017/11/8 14:36:53）');
            }
            $need = [];
            $qty = $objWorksheet->getCell('L' . $row)->getValue();//商品数量
            if (!is_numeric($qty)) {
                throw new \Exception('【L 列】商品数量必需是整数！');
            }
            $need['originalamount'] = round(floatval($objWorksheet->getCell('I' . $row)->getValue()), 2);//原价
            if (!is_numeric($need['originalamount'])) {
                throw new \Exception('【I 列】原价必需是数字！');
            }
            $need['unitamount'] = round(floatval($objWorksheet->getCell('K' . $row)->getValue()), 2);//原价
            if (!is_numeric($need['originalamount'])) {
                throw new \Exception('【K 列】单价必需是数字！');
            }
            $need['qty'] = (int)$qty;
            if ($need['qty'] == 0) {
                continue;
            }
            $need['originalamount'] = $need['originalamount'] * $need['qty'];
            $need['netamount'] = round(floatval($objWorksheet->getCell('M' . $row)->getValue()), 2);//付款金额
            $needList[$tradeNo]['itemList'][] = $need;
            $needList[$tradeNo]['tradeTime'] = $tradeTime;
            $needList[$tradeNo]['tradeNo'] = $tradeNo;
        }
        return $needList;
    }

    /**
     * @return array
     * @throws \Exception
     */
    private function parseCSV()
    {
        $file_name = APP_PATH . 'Upload/' . $this->platform['type_code'] . '/' . $this->upload['save_name'];
        $handle = fopen($file_name, "r");
        $i = 0;
        $needList = [];
        while ($data = fgetcsv($handle, 1000, ",")) {
            if ($i == 0) {
                $i++;
                continue;
            }
            $tradeNo = str_replace('"', '', trim($data[1], '='));
            if (!$this->regularAscii($tradeNo)) {
                throw new \Exception('单据单号 不能是中文');
            }
            $tradeDate = str_replace('"', '', trim($data[0], '='));
            $tradeTime = strtotime($tradeDate);
            if (!$tradeTime) {
                throw new \Exception('交易时间 格式有误（格式为：2017/11/8 14:36:53）');
            }
            $qty = str_replace('"', '', trim($data[7], '='));
            if (!is_numeric($qty)) {
                throw new \Exception('商品数量必需是整数！');
            }
            if ($qty == 0) {
                continue;
            }
            $originalamount = round(floatval(str_replace('"', '', trim($data[4], '='))), 2);
            if (!is_numeric($originalamount)) {
                throw new \Exception('原价必需是数字！');
            }
            $netamount = round(floatval(str_replace('"', '', trim($data[8], '='))), 2);
            if (!is_numeric($originalamount)) {
                throw new \Exception('付款金额必需是数字！');
            }
            $unitamount = round(floatval(str_replace('"', '', trim($data[6], '='))), 2);
            if (!is_numeric($unitamount)) {
                throw new \Exception('货品单价必需是数字！');
            }
            $originalamount = $originalamount * $qty;
            $needList[$tradeNo]['itemList'][] = [
                'originalamount' => $originalamount,
                'unitamount' => $unitamount,
                'netamount' => $netamount,
                'qty' => (int)$qty,
            ];
            $needList[$tradeNo]['tradeTime'] = $tradeTime;
            $needList[$tradeNo]['tradeNo'] = $tradeNo;
        }
        fclose($handle);
        return $needList;
    }

    /**
     * 鲁能飞鹰店xlsx格式不同，数据单独处理
     */
    private function lnfydDataHandle()
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
        } catch (\Exception $e) {
            throw new \Exception('Excel文件有误！');
        }
        $file_name = APP_PATH . 'Upload/' . $this->platform['type_code'] . '/' . $this->upload['save_name'];
        $PHPExcel = $reader->load($file_name); // 文档名称
        $objWorksheet = $PHPExcel->getActiveSheet();
        $highestRow = $objWorksheet->getHighestRow();
        //获取excel数据
        $needList = [];
        for ($row = 15; $row <= $highestRow; $row++) {
            $tradeNo = $objWorksheet->getCell('A' . $row)->getValue();
            if (!$tradeNo) {
                continue;
            }
            if (!$this->regularAscii($tradeNo)) {
                throw new \Exception('【C 列】单据单号 不能是中文');
            }
            $tradeTime = $objWorksheet->getCell('B' . $row)->getValue();
            $tradeTime = strtotime($tradeTime);
            if (!$tradeTime) {
                throw new \Exception('【B 列】交易时间 格式有误（格式为：2017-11-08 14:36:53）');
            }
            $need = [];
            $need['qty'] = 1;
            $need['itemcode'] = $objWorksheet->getCell('J' . $row)->getValue();//商品编号

            $need['originalamount'] = round(floatval($objWorksheet->getCell('X' . $row)->getValue()), 2);//原价
            if (!is_numeric($need['originalamount'])) {
                throw new \Exception('【X 列】原价必需是数字！');
            }
            $need['unitamount'] = round(floatval($objWorksheet->getCell('Y' . $row)->getValue()), 2);//原价
            if (!is_numeric($need['originalamount'])) {
                throw new \Exception('【Y 列】单价必需是数字！');
            }
            $need['netamount'] = round(floatval($objWorksheet->getCell('AE' . $row)->getValue()), 2);//付款金额
            $need['originalamount'] = $need['netamount'];
            if ($need['netamount'] < 0) {
                $need['qty'] = -1;
            }
            $needList[$tradeNo]['itemList'][] = $need;
            $needList[$tradeNo]['tradeTime'] = $tradeTime;
            $needList[$tradeNo]['tradeNo'] = $tradeNo;
        }
        if (count($needList) == 0) {
            throw new \Exception('文档记录为空！');
        }
        iconv("ASCII", "UTF-8//IGNORE", 9);
        if ($this->platform['ftp_need'] == 1) {
            $this->ftpDataHandle($needList);
        }
        if ($this->platform['wsdl_need'] == 1) {
            $this->wsdlDataHandle($needList);
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
            throw new \Exception('已经存在ftp文件');
        }
        $uploadFtpModel->create([
            'status' => 0,
            'upload_id' => $this->upload['id'],
            'filename' => $filename . '.' . $this->upload['id'],
        ]);
        $uploadFtpModel->add($uploadFtpModel->data());
    }


    private function ftpSync()
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
            $itemList = $need['itemList'];
            $i = 1;
            foreach ($itemList as $key => $item) {
                if ($item['qty'] == 0) {
                    unset($itemList[$key]);
                    continue;
                }
                $totalOriginalAmount += $item['originalamount'];
                $totalQty += $item['qty'];
                $totalNetAmount += $item['netamount'];
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
                'netqty' => $totalNetAmount > 0 ? 1 : -1,
                'originalamount' => $totalNetAmount,
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
            $params['salesitems'] = [
                [
                    'itemcode' => $this->platformWsdlConf['itemcode'],
                    'lineno' => 1,
                    'invttype' => 0,
                    'qty' => $totalNetAmount > 0 ? 1 : -1,
                    'netamount' => $totalNetAmount,
                    'originalprice' => $totalNetAmount,
                    'sellingprice' => $totalNetAmount,
                    'vipdiscountpercent' => 1,
                    'vipdiscountless' => 0,
                    'totaldiscountless1' => 0,
                    'totaldiscountless2' => 0,
                    'totaldiscountless' => 0,
                    'bonusearn' => 0,
                    'exstk2sales' => 0,
                ]
            ];
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
            $uploadWsdlModel->create([
                'upload_id' => $this->upload['id'],
                'trade_no' => $tradeNo,
                'trade_date' => date('Y-m-d', $need['tradeTime']),
                'netamount' => $totalNetAmount,
                'request_data' => json_encode(['astr_request' => $params]),
                'qty' => $totalNetAmount > 0 ? 1 : -1,
                'status' => 0
            ]);
            $uploadWsdlModel->add($uploadWsdlModel->data());
        }
    }

    private function wsdlSync()
    {
        $uploadWsdlModel = new UploadWsdlModel();
        $uploadWsdlList = $uploadWsdlModel->where(['upload_id' => $this->upload['id']])->where('status!=1')->select();
        if (!count($uploadWsdlList)) {
            $this->errorCode = 1;
            $this->printHandel('不存在单据记录');
            return;
        }
        foreach ($uploadWsdlList as $uploadWsdl) {
            $this->printHandel('请求接口,交易单号:' . $uploadWsdl['trade_no'] . ' ...');
            $otherUpload = $uploadWsdlModel->where(['trade_no' => $uploadWsdl['trade_no'], 'status' => '1'])->find();
            if ($otherUpload) {
                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求成功！');
                $result = [];
                $uploadWsdl['status'] = 1;
            } else {
                $result = $this->createRequest('postsalescreate', json_decode($uploadWsdl['request_data'], true));
                if (!$result) {
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求异常！');
                    continue;
                }
                if ($result['postsalescreateResult']['header']['responsecode'] == 0 || $result['postsalescreateResult']['header']['responsecode'] == -100 || (strpos($result['postsalescreateResult']['header']['responsemessage'], 'exists') !== false)) {
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
            foreach ($need['itemList'] as $item) {
                if ($item['qty'] == 0) {
                    continue;
                }
                $totalOriginalAmount += $item['originalamount'];
                $totalQty += $item['qty'];
                $totalNetAmount += $item['netamount'];
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
                '数量' => $totalNetAmount > 0 ? 1 : -1,
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

            $uploadWsdlModel->create([
                'upload_id' => $this->upload['id'],
                'trade_no' => $tradeNo,
                'trade_date' => date('Y-m-d', $need['tradeTime']),
                'netamount' => $totalNetAmount,
                'request_data' => json_encode($params),
                'qty' => $totalNetAmount > 0 ? 1 : -1,
                'status' => 0
            ]);
            $uploadWsdlModel->add($uploadWsdlModel->data());
        }
    }

    /**
     * 长沙环宇店数据处理 xtdyc
     * @param $needList
     */
    private function xtdycDataHandle($needList)
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
            $uploadWsdlModel->create([
                'upload_id' => $this->upload['id'],
                'trade_no' => $tradeNo,
                'trade_date' => date('Y-m-d', $need['tradeTime']),
                'netamount' => $totalNetAmount,
                'request_data' => json_encode($params),
                'qty' => $totalNetAmount > 0 ? 1 : -1,
                'status' => 0
            ]);
            $uploadWsdlModel->add($uploadWsdlModel->data());
        }
    }

    /**
     * 长沙环宇店数据处理 xtdyc
     * @param $needList
     */
    private function asqDataHandle($needList)
    {
        $uploadWsdlModel = new UploadWsdlModel();
        $uploadModel = new UploadModel();
        $uploadList = $uploadModel->where(['platform_id' => $this->upload['platform_id']])->select();
        if (!count($uploadList)) {
            $this->error('数据有误,请重新上传');
        }
        $uploadIdList = array_column($uploadList, 'id');
        foreach ($needList as $tradeNo => $need) {
            $tradeDate = date('Y-m-d', $need['tradeTime']);
            break;
        }
        $last = $uploadWsdlModel->where('upload_id in (' . implode(',', $uploadIdList) . ')')
            ->where(['trade_date' => $tradeDate])
            ->order('sort desc')->find();
        $count = 0;
        if ($last) {
            $count = $last['sort'];
        }
        $flag = 1;
        foreach ($needList as $tradeNo => $need) {
            $params = [];
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
            $count = $count + 1;
            $params['transStr'] = implode(',', [
                $this->platformWsdlConf['mallid'],//商铺编号
                $this->platformWsdlConf['tillid'],//POS 编号
                date('ymd', $need['tradeTime']) . str_pad($count, '4', '0', STR_PAD_LEFT),
                $this->platformWsdlConf['storecode'],//商品编码
                $this->platformWsdlConf['itemcode'],//商品名称
                $totalNetAmount,//交易金额
                date('Y-m-d H:i', $need['tradeTime']),//交易日期
            ]);
            $uploadWsdlModel = new UploadWsdlModel();
            $uploadWsdlModel->create([
                'upload_id' => $this->upload['id'],
                'trade_no' => $tradeNo,
                'trade_date' => date('Y-m-d', $need['tradeTime']),
                'netamount' => $totalNetAmount,
                'request_data' => json_encode($params),
                'sort' => $count,
                'qty' => $totalNetAmount > 0 ? 1 : -1,
                'status' => 0
            ]);
            $addStatus = $uploadWsdlModel->add($uploadWsdlModel->data());
            if ($addStatus) {
                $flag = 0;
            }
        }
        if ($flag == 1) {
            throw new Exception('单据数据均已存在，请勿再次上传');
        }
    }


    /**
     * 怡丰城上传 shyfc
     */
    private function shyfcSync()
    {
        $uploadWsdlModel = new UploadWsdlModel();
        $uploadWsdlList = $uploadWsdlModel->where(['upload_id' => $this->upload['id']])->where('status!=1')->select();
        if (!count($uploadWsdlList)) {
            $this->errorCode = 1;
            $this->printHandel('不存在单据记录');
            return;
        }
        foreach ($uploadWsdlList as $uploadWsdl) {
            $this->printHandel('请求接口,交易单号:' . $uploadWsdl['trade_no'] . ' ...');
            $otherUpload = $uploadWsdlModel->where(['trade_no' => $uploadWsdl['trade_no'], 'status' => '1'])->find();
            if ($otherUpload) {
                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求成功！');
                $result = [];
                $uploadWsdl['status'] = 1;
            } else {
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
            }


            $uploadWsdl['response_data'] = json_encode($result);
            $uploadWsdlModel->create($uploadWsdl);
            $uploadWsdlModel->save($uploadWsdlModel->data());
        }
    }

    /**
     * 长沙环宇店上传 xtdyc
     */
    private function xtdycSync()
    {
        $uploadWsdlModel = new UploadWsdlModel();
        $uploadWsdlList = $uploadWsdlModel->where(['upload_id' => $this->upload['id']])->where('status!=1')->select();
        if (!count($uploadWsdlList)) {
            $this->errorCode = 1;
            $this->printHandel('不存在单据记录');
            return;
        }
        foreach ($uploadWsdlList as $uploadWsdl) {
            $this->printHandel('请求接口,交易单号:' . $uploadWsdl['trade_no'] . ' ...');
            $otherUpload = $uploadWsdlModel->where(['trade_no' => $uploadWsdl['trade_no'], 'status' => '1'])->find();
            if ($otherUpload) {
                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求成功！');
                $result = [];
                $uploadWsdl['status'] = 1;
            } else {
                $result = $this->createRequest('PostSales', json_decode($uploadWsdl['request_data'], true));
                if (!$result) {
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求异常！');
                    return;
                }
                $result = xml2array($result['PostSalesResult']['any']);

                if ($result['Response']['Result']['ErrorCode'] == 0) {
                    $uploadWsdl['status'] = 1;
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口成功');
                } else {
                    $this->errorCode = 1;
                    $uploadWsdl['status'] = 2;
                    if (is_array($result['Response']['Result']['ErrorMessage'])) {
                        $error_message = json_encode($result['Response']['Result']['ErrorMessage']);
                    } else {
                        $error_message = $result['Response']['Result']['ErrorMessage'];
                    }
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口失败，返回值【' . $result['Response']['Result']['ErrorCode'] . '】' . ' 错误信息：' . $error_message);
                }
            }
            $uploadWsdl['response_data'] = json_encode($result);
            $uploadWsdlModel->create($uploadWsdl);
            $uploadWsdlModel->save($uploadWsdlModel->data());
        }
    }


    private function asqSync()
    {
        $uploadWsdlModel = new UploadWsdlModel();
        $uploadWsdlList = $uploadWsdlModel->where(['upload_id' => $this->upload['id']])->where('status!=1')->select();
        if (!count($uploadWsdlList)) {
            $this->errorCode = 1;
            $this->printHandel('不存在单据记录');
            return;
        }
        foreach ($uploadWsdlList as $uploadWsdl) {
            $this->printHandel('请求接口,交易单号:' . $uploadWsdl['trade_no'] . ' ...');
            $otherUpload = $uploadWsdlModel->where(['trade_no' => $uploadWsdl['trade_no'], 'status' => '1'])->find();
            if ($otherUpload) {
                $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求成功！');
                $result = [];
                $uploadWsdl['status'] = 1;
            } else {
                $result = $this->createRequest('TrasAdd', json_decode($uploadWsdl['request_data'], true));
                if (!$result) {
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求异常！');
                    return;
                }
                if ($result['TrasAddResult']) {
                    $uploadWsdl['status'] = 1;
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口成功');
                } else {
                    $this->errorCode = 1;
                    $uploadWsdl['status'] = 2;
                    $error_message = json_encode($result);
                    $this->printHandel('交易单号:' . $uploadWsdl['trade_no'] . ' 请求接口失败，返回值【' . $result['Response']['Result']['ErrorCode'] . '】' . ' 错误信息：' . $error_message);
                }
            }
            $uploadWsdl['response_data'] = json_encode($result);
            $uploadWsdlModel->create($uploadWsdl);
            $uploadWsdlModel->save($uploadWsdlModel->data());
        }
    }

}

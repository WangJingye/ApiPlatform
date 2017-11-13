<?php

namespace Home\Controller;


use Common\Model\PlatformFtpConfModel;
use Common\Model\PlatformModel;
use Common\Model\PlatformWsdlConfModel;

class PlatformController extends BaseController
{
    public function __construct()
    {
        parent::__construct();
        $this->assign('leftKey','Platform');

    }

    /**
     *
     */
    public function index()
    {
        $model = new PlatformModel();

        $list = $model->select();
        $this->assign('list', $list);
        $this->display();
    }

    public function add()
    {
        if (IS_POST) {
            $model = new PlatformModel();
            $model->create($_POST);
            $model->add($model->data());
            $this->redirect('Home/Platform/index');
        }
        $this->display();
    }

    public function update()
    {
        $platformId = $_GET['platform_id'];
        $model = new PlatformModel();
        $platform = $model->where(['platform_id' => $platformId])->find();
        if (IS_POST) {
            $model->create($_POST);
            $model->save($model->data());
            $this->redirect('Home/Platform/index');
        }
        $this->assign('platform', $platform);
        $this->display('add');
    }

    public function setFtp()
    {
        $platformId = $_GET['platform_id'];
        $model = new PlatformFtpConfModel();
        $platform = (new PlatformModel())->where(['platform_id' => $platformId])->find();
        $platformFtpConf = (new PlatformFtpConfModel())->where(['platform_id' => $platform['platform_id']])->find();
        if (IS_POST) {
            $model->create($_POST);
            if(!$platformFtpConf){
                $model->add($model->data());

            }else{
                $model->save($model->data());
            }
            $this->redirect('Home/Platform/index');
        }
        $this->assign('platform', $platform);
        $this->assign('ftp', $platformFtpConf);
        $this->display();
    }

    public function setWsdl()
    {
        $platformId = $_GET['platform_id'];
        $model = new PlatformWsdlConfModel();
        $platform = (new PlatformModel())->where(['platform_id' => $platformId])->find();
        $platformWsdlConf = $model->where(['platform_id' => $platform['platform_id']])->find();
        if (IS_POST) {
            $model->create($_POST);
            if(!$platformWsdlConf){
                $model->add($model->data());

            }else{
                $model->save($model->data());

            }
            $this->redirect('Home/Platform/index');
        }
        $this->assign('platform', $platform);
        $this->assign('wsdl', $platformWsdlConf);
        $this->display();
    }

}

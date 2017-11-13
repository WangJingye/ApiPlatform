<?php

namespace Home\Controller;


use Common\Model\UserModel;
use Think\Exception;

class UserController extends BaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->assign('leftKey', 'User');
    }

    /**
     *
     */
    public function index()
    {
        $model = new UserModel();

        $where = [];
        if (isset($_GET['username']) && $_GET['username']) {
            $where['username'] = $_GET['username'];
        }
        if (isset($_GET['platform_id']) && $_GET['platform_id']) {
            $where['platform_id'] = $_GET['platform_id'];
        }
        //只列出非管理员用户
        $where['is_admin'] = 0;
        $userList = $model->where($where)->select();
        $this->assign('userList', $userList);
        $this->assign('search', $_GET);
        $this->display();


    }

    public function add()
    {
        try {
            if (IS_POST) {
                if (!isset($_POST['username']) || !$_POST['username']) {
                    throw new Exception(json_encode([
                        'username' => '用户名不能为空！'
                    ]));
                }
                if (!isset($_POST['platform_id']) || !$_POST['platform_id']) {
                    throw new Exception(json_encode([
                        'platform_id' => '店铺未选择！'
                    ]));
                }
                if (!isset($_POST['password']) || !$_POST['password']) {
                    throw new Exception(json_encode([
                        'password' => '初始密码未设置！'
                    ]));
                }
                $model = new UserModel();
                $user = $model->where(['username' => $_POST['username']])->find();
                if ($user) {
                    throw new Exception(json_encode([
                        'username' => '用户名已存在！'
                    ]));
                }
                $data = [
                    'username' => $_POST['username'],
                    'password' => $_POST['password'],
                    'platform_id' => $_POST['platform_id']
                ];
                $model->create($data);
                $model->add($model->data());
                $this->redirect('Home/user/index');
            }
        } catch (Exception $e) {
            $this->assign('error_info', json_decode($e->getMessage(), true));
        }
        $this->assign('search', $_POST);
        $this->display();
    }

    public function changePassword()
    {
        try {
            if (!$this->user['is_admin'] && $this->user['user_id'] != $_POST['user_id']) {
                throw  new Exception('参数有误！');
            }
            if ($_POST['password'] != $_POST['re_password']) {
                throw  new Exception('两次密码不一致！');
            }
            $model = new UserModel();
            $user = $model->where(['user_id' => $_POST['user_id']])->find();
            if (!$user) {
                throw  new Exception('用户信息有误！');
            }
            $user['password'] = $_POST['password'];
            $model->create($user);
            $model->save($model->data());
            $jumpUrl = '';
            //修改个人密码让他重新登录
            if ($this->user['user_id'] == $_POST['user_id']) {
                session('user', null);
                $jumpUrl = U('Home/User/login');
            }
            echo json_encode(['code' => '0', 'msg' => '密码已修改', 'jumpUrl' => $jumpUrl]);
        } catch (Exception $e) {
            echo json_encode(['code' => '-1', 'msg' => $e->getMessage()]);
        }


    }

    public function login()
    {
        try {
            if (IS_POST) {
                if (!isset($_POST['username']) || !$_POST['username']) {
                    throw new Exception('用户名不能为空！');
                }
                if (!isset($_POST['password']) || !$_POST['password']) {
                    throw new Exception('密码不能为空！');
                }
                $model = new UserModel();
                $user = $model->where(['username' => $_POST['username'], 'password' => $_POST['password']])->find();
                if (!$user) {
                    throw new Exception('用户名密码错误！');
                }
                if($user['status']==0){
                    throw new Exception('用户已冻结，请与管理员联系！');
                }
                session('user', $user);
                $this->redirect('Home/Upload/index');
            }
        } catch (Exception $e) {
            $this->assign('error_message', $e->getMessage());
        }
        layout(false);
        $this->display();
    }

    public function logout()
    {
        session('user', null);
        $this->redirect('login');
    }

    public function freeze()
    {
        try {
            if (!isset($_GET['user_id']) || !$_GET['user_id']) {
                throw  new Exception('参数有误！');
            }
            if (!$this->user['is_admin']) {
                throw  new Exception('没有权限！');
            }
            if ($this->user['user_id'] == $_GET['user_id']) {
                throw  new Exception('参数有误！');
            }
            $model = new UserModel();
            $user = $model->where(['user_id' => $_GET['user_id']])->find();
            if (!$user) {
                throw  new Exception('操作有误！');
            }
            if ($user['status'] == 0) {
                throw  new Exception('用户已冻结！');
            }

            $user['status'] = 0;
            $model->create($user);
            $model->save($model->data());
            $this->redirect('index');
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }

    }

    public function unfreeze()
    {
        try {
            if (!isset($_GET['user_id']) || !$_GET['user_id']) {
                throw  new Exception('参数有误！');
            }
            if (!$this->user['is_admin']) {
                throw  new Exception('没有权限！');
            }
            if ($this->user['user_id'] == $_GET['user_id']) {
                throw  new Exception('参数有误！');
            }
            $model = new UserModel();
            $user = $model->where(['user_id' => $_GET['user_id']])->find();
            if (!$user) {
                throw  new Exception('操作有误！');
            }
            if ($user['status'] == 1) {
                throw  new Exception('用户已解冻！');
            }

            $user['status'] = 1;
            $model->create($user);
            $model->save($model->data());
            $this->redirect('index');
        } catch (Exception $e) {
            $this->error($e->getMessage());
        }

    }

}

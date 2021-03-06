<?php

namespace app\admin\controller;
use app\admin\model\AuthGroupAccess;
use app\admin\model\AuthRule;
use app\admin\model\AuthGroup;
use app\admin\model\Member;
use think\Input;

class Auth extends Admin
{
    /**
     * 后台节点配置的url作为规则存入auth_rule
     * 执行新节点的插入,已有节点的更新,无效规则的删除三项任务
     */
    public function updateRules(){
        //需要新增的节点必然位于$nodes
        $nodes = $this->returnNodes(false);

        $map = array('module'=>'admin','type'=>array('in','1,2'));//status全部取出,以进行更新
        //需要更新和删除的节点必然位于$rules
        $rules = AuthRule::all(function($query) use($map) {
            $query->where($map);
        });

        //构建insert数据
        $data = array();//保存需要插入和更新的新节点
        foreach ($nodes as $value){
            $temp['name']   = $value['url'];
            $temp['title']  = $value['title'];
            $temp['module'] = 'admin';
            if($value['pid'] > 0){
                $temp['type'] = AuthRule::RULE_URL;
            }else{
                $temp['type'] = AuthRule::RULE_MAIN;
            }
            $temp['status'] = 1;
            $data[strtolower($temp['name'].$temp['module'].$temp['type'])] = $temp;//去除重复项
        }

        $update = array();//保存需要更新的节点
        $ids    = array();//保存需要删除的节点的id
        foreach ($rules as $index=>$rule){
            $key = strtolower($rule['name'].$rule['module'].$rule['type']);
            if ( isset($data[$key]) ) {//如果数据库中的规则与配置的节点匹配,说明是需要更新的节点
                $data[$key]['id'] = $rule['id'];//为需要更新的节点补充id值
                $update[] = $data[$key];
                unset($data[$key]);
                unset($rules[$index]);
                unset($rule['condition']);
                $diff[$rule['id']]=$rule;
            }elseif($rule['status']==1){
                $ids[] = $rule['id'];
            }
        }
        

        if(count($update)) {
            foreach ($update as $k=>$row){
                if ( $row!=$diff[$row['id']] ) {
                    AuthRule::where(array('id'=>$row['id']))->update($row);
                }
            }
        }

        if(count($ids)) {
            // 删除规则是否需要从每个用户组的访问授权表中移除该规则?
            AuthRule::where( array( 'id'=>array('IN',implode(',',$ids)) ) )->update(array('status'=>-1));
        }

        if(count($data)) {
            foreach ($data as $value) {
                AuthRule::create($value);
            }
        }
    }

    /**
     * 权限管理首页
     */
    public function index(){
        $list = $this->lists('AuthGroup',array('module'=>'admin', 'status'=>array('egt', 0)),'id asc');
        $list = int_to_string($list);
        $this->assign( '_list', $list );
        $this->assign( '_use_tip', true );

        return $this->fetch();
    }

    /**
     * 创建用户组
     */
    public function createGroup(){
        $GroupModel = new AuthGroup();
        $GroupModel->title = input('title');
        $GroupModel->description = input('description');
        if (empty($GroupModel->title)){
            return $this->error('用户组名称不能为空');
        }
        $result = $GroupModel->save();

        if($result) {
            return $this->success("添加成功");
        } else {
            return $this->error("添加失败:".$GroupModel->getError());
        }
    }

    /**
     * 修改用户组
     */
    public function edit(){
        $GroupModel = new AuthGroup();
        $data = input('post.');
        $result = $GroupModel->update($data);
        if($result) {
            return $this->success("修改成功");
        } else {
            return $this->error("修改失败:".$GroupModel->getError());
        }
    }

    /**
     * 编辑管理员用户组
     */
    public function editGroup(){
        $auth_group = M('AuthGroup')->where( array('module'=>'admin','type'=>AuthGroup::TYPE_ADMIN) )
                                    ->find( (int)$_GET['id'] );
        $this->assign('auth_group',$auth_group);

        $this->display();
    }


    /**
     * 访问授权页面
     */
    public function access(){
        $this->updateRules();

        $map = array('status'=>array('egt','0'),'module'=>'Admin','type'=>AuthGroup::TYPE_ADMIN);
        $auth_group = AuthGroup::where($map)->column("id,title,rules");
        $node_list  = $this->returnNodes();
        $map = array('module'=>'Admin','type'=>AuthRule::RULE_MAIN,'status'=>1);
        $main_rules = AuthRule::where($map)->column("name,id");
        $map = array('module'=>'Admin','type'=>AuthRule::RULE_URL,'status'=>1);
        $child_rules = AuthRule::where($map)->column("name,id");

        $this->assign('main_rules', $main_rules);
        $this->assign('auth_rules', $child_rules);
        $this->assign('node_list',  $node_list);
        $this->assign('auth_group', $auth_group);
        $this->assign('this_group', $auth_group[(int)input('group_id')]);

        return $this->fetch('managergroup');
    }

    /**
     * 管理员用户组数据写入/更新
     */
    public function writeGroup() {
        $rules = input('rules/a');
        $data = array();
        if(isset($rules)) {
            sort($rules);
            $data['rules'] = implode(',' , array_unique($rules));
        }
        $data['module'] = 'admin';
        $data['type'] = AuthGroup::TYPE_ADMIN;

        $AuthGroupModel = new AuthGroup();
        $result = $AuthGroupModel->update($data, ['id'=> input('id')]);
        if($result) {
            return $this->success('操作成功!');
        } else {
            return $this->error('操作失败:'.$AuthGroupModel->getError());
        }
    }

    /**
     * 状态修改
     */
    public function changeStatus($method=null){
        if ( empty(input('id')) ) {
            $this->error('请选择要操作的数据!');
        }
        $result = false;
        $method = input('method');
        switch (strtolower($method) ){
            case 'forbidgroup':
                $result = AuthGroup::where(['id'=>input('id')])->update(['status'=>0]);
                break;
            case 'resumegroup':
                $result = AuthGroup::where(['id'=>input('id')])->update(['status'=>1]);
                break;
            case 'deletegroup':
                $result = AuthGroup::where(['id'=>input('id')])->update(['status'=>-1]);
                break;
        }

        if($result) {
            return $this->success('操作成功!');
        } else {
            return $this->error('操作失败');
        }
    }

    /**
     * 用户组授权用户列表
     */
    public function user(){
        $map['group_id'] = input('group_id');
        $list = $this->lists('AuthGroupAccess', $map);

        $this->assign('list', $list);

        return $this->fetch();
    }

    /**
     * 将分类添加到用户组的编辑页面
     */
    public function category(){
        $auth_group     =   M('AuthGroup')->where( array('status'=>array('egt','0'),'module'=>'admin','type'=>AuthGroup::TYPE_ADMIN) )
            ->getfield('id,id,title,rules');
        $group_list     =   D('Category')->getTree();
        $authed_group   =   AuthGroup::getCategoryOfGroup(I('group_id'));
        $this->assign('authed_group',   implode(',',(array)$authed_group));
        $this->assign('group_list',     $group_list);
        $this->assign('auth_group',     $auth_group);
        $this->assign('this_group',     $auth_group[(int)$_GET['group_id']]);
        $this->meta_title = '分类授权';
        $this->display();
    }

    public function tree($tree = null){
        $this->assign('tree', $tree);
        $this->display('tree');
    }

    /**
     * 将用户添加到用户组的编辑页面
     */
    public function group(){
        $uid            =   I('uid');
        $auth_groups    =   D('AuthGroup')->getGroups();
        $user_groups    =   AuthGroup::getUserGroup($uid);
        $ids = array();
        foreach ($user_groups as $value){
            $ids[]      =   $value['group_id'];
        }
        $nickname       =   D('Member')->getNickName($uid);
        $this->assign('nickname',   $nickname);
        $this->assign('auth_groups',$auth_groups);
        $this->assign('user_groups',implode(',',$ids));
        return json_encode(implode(',',$ids));
//        $this->meta_title = '用户组授权';
        $this->display();
    }

    /**
     * 将用户添加到用户组,入参uid,group_id
     */
    public function addToGroup(){
        $uid = input('id');
        $gid = input('group_id');
        if(empty($uid)){
            return $this->error('参数有误');
        }
        $AuthGroup = new AuthGroup();
        if(is_numeric($uid)){
            if(is_administrator($uid)) {
                return $this->error('该用户为超级管理员');
            }
            if(!Member::get($uid)) {
                return $this->error('用户不存在');
            }
        }

        $access = AuthGroupAccess::where(['uid'=>$uid])->find();
        if(empty($access)) {
            $result = AuthGroupAccess::create(['uid' => $uid, 'group_id' => $gid]);
        } else {
            $result = AuthGroupAccess::update(['group_id'=> $gid], ['uid'=>$uid]);
        }
        if($result){
            return $this->success('操作成功');
        } else {
            return $this->error('操作失败');
        }
    }

    /**
     * 将用户从用户组中移除  入参:uid,group_id
     */
    public function removeFromGroup(){
        $uid = input('uid');
        $gid = input('group_id');
        if($uid == UID){
            return $this->error('不允许解除自身授权');
        }
        if( empty($uid) || empty($gid) ){
            return $this->error('参数有误');
        }

        $map = array('uid' => $uid, 'group_id'=>$gid );
        if (AuthGroupAccess::where($map)->delete()){
            return $this->success('操作成功');
        }else{
            return $this->error('操作失败');
        }
    }

    /**
     * 将分类添加到用户组  入参:cid,group_id
     */
    public function addToCategory(){
        $cid = input('cid');
        $gid = input('group_id');
        if( empty($gid) ){
            return $this->error('参数有误');
        }
        $AuthGroup = D('AuthGroup');
        if( !$AuthGroup->find($gid)){
            return $this->error('用户组不存在');
        }
        if( $cid && !$AuthGroup->checkCategoryId($cid)){
            return $this->error($AuthGroup->error);
        }
        if ( $AuthGroup->addToCategory($gid,$cid) ){
            return $this->success('操作成功');
        }else{
            return $this->error('操作失败');
        }
    }

    /**
     * 将模型添加到用户组  入参:mid,group_id
     */
    public function addToModel(){
        $mid = input('id');
        $gid = input('get.group_id');
        if( empty($gid) ){
            return $this->error('参数有误');
        }
        $AuthGroup = D('AuthGroup');
        if( !$AuthGroup->find($gid)){
            return $this->error('用户组不存在');
        }
        if( $mid && !$AuthGroup->checkModelId($mid)){
            return $this->error($AuthGroup->error);
        }
        if ( $AuthGroup->addToModel($gid,$mid) ){
            return $this->success('操作成功');
        }else{
            return $this->error('操作失败');
        }
    }

}

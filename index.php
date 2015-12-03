<?php

//此页面为微信登陆页面，若数据库中存在该用户则直接登录，若没有则绑定现有账户或新建账户

    require_once "weixin.class.php";
    require_once "transport.class.php";

    function getMConfig(){
        $file_name=md5("m_config");
        $GLOBALS['fcache']->set_dir(MAPI_DATA_CACHE_DIR);
        $m_config = $GLOBALS['fcache']->get($file_name);
        if($m_config===false)
        {
            $m_config = array();
            $sql = "select code,val from ".DB_PREFIX."m_config";
            $list = $GLOBALS['db']->getAll($sql);
            foreach($list as $item){
                $m_config[$item['code']] = $item['val'];
            }
            $GLOBALS['fcache']->set_dir(MAPI_DATA_CACHE_DIR);
            $GLOBALS['fcache']->set($file_name,$m_config);
        }
        return $m_config;
    }
    
    
    function isWeixin(){
        $agent = strtolower($_SERVER['HTTP_USER_AGENT']);
        $is_weixin = strpos($agent, 'micromessenger') ? true : false ;
        if($is_weixin){
            return true;
        }else{
            return false;
        }
    }
    
    
    function get_domain()
    {
        /* 协议 */
        $protocol = get_http();
    
        if(app_conf("SITE_DOMAIN")!="")
        {
            return $protocol.app_conf("SITE_DOMAIN");
        }
    
        /* 域名或IP地址 */
        if (isset($_SERVER['HTTP_X_FORWARDED_HOST']))
        {
            $host = $_SERVER['HTTP_X_FORWARDED_HOST'];
        }
        elseif (isset($_SERVER['HTTP_HOST']))
        {
            $host = $_SERVER['HTTP_HOST'];
        }
        else
        {
            /* 端口 */
            if (isset($_SERVER['SERVER_PORT']))
            {
                $port = ':' . $_SERVER['SERVER_PORT'];
    
                if ((':80' == $port && 'http://' == $protocol) || (':443' == $port && 'https://' == $protocol))
                {
                    $port = '';
                }
            }
            else
            {
                $port = '';
            }
    
            if (isset($_SERVER['SERVER_NAME']))
            {
                $host = $_SERVER['SERVER_NAME'] . $port;
            }
            elseif (isset($_SERVER['SERVER_ADDR']))
            {
                $host = $_SERVER['SERVER_ADDR'] . $port;
            }
        }
    
        return $protocol . $host;
    }
    
    
    
    function get_user_has($key,$value){
        $row=$GLOBALS['db']->getRow("select * from  ".DB_PREFIX."user where $key='".$value."'");
        if($row){
            return $row;
        }else{
            return false;
        }
    }
    
    
    
    /**
     * 处理会员登录
     * @param $user_name_or_email 用户名或邮箱地址
     * @param $user_pwd 密码
     *
     */
    function do_login_user($user_name_or_email,$user_pwd)
    {
        $user_data = $GLOBALS['db']->getRow("select * from ".DB_PREFIX."user where (user_name='".$user_name_or_email."' or email = '".$user_name_or_email."' or mobile = '".$user_name_or_email."' )");
        //载入会员整合
        $integrate_code = trim(app_conf("INTEGRATE_CODE"));
        if($integrate_code!='')
        {
            $integrate_file = APP_ROOT_PATH."system/integrate/".$integrate_code."_integrate.php";
            if(file_exists($integrate_file))
            {
                require_once $integrate_file;
                $integrate_class = $integrate_code."_integrate";
                $integrate_obj = new $integrate_class;
            }
        }
        if($integrate_obj)
        {
            $result = $integrate_obj->login($user_name_or_email,$user_pwd);
            	
        }
    
        $user_data = $GLOBALS['db']->getRow("select * from ".DB_PREFIX."user where (user_name='".$user_name_or_email."' or email = '".$user_name_or_email."' or mobile = '".$user_name_or_email."' )");
        if(!$user_data)
        {
            $result['status'] = 0;
            $result['data'] = ACCOUNT_NO_EXIST_ERROR;
            return $result;
        }
        else
        {
            $result['user'] = $user_data;
            if($user_data['user_pwd'] != md5($user_pwd.$user_data['code'])&&$user_data['user_pwd']!=$user_pwd)
            {
                $result['status'] = 0;
                $result['data'] = ACCOUNT_PASSWORD_ERROR;
                return $result;
            }
            elseif($user_data['is_effect'] != 1)
            {
                $result['status'] = 0;
                $result['data'] = ACCOUNT_NO_VERIFY_ERROR;
                return $result;
            }
            else
            {
    
                if(intval($result['status'])==0) //未整合，则直接成功
                {
                    $result['status'] = 1;
                }
    
                $build_count = $GLOBALS['db']->getOne("select count(*) from ".DB_PREFIX."deal where is_delete = 0 and is_effect = 1 and user_id = ".$user_data['id']);
                $focus_count = $GLOBALS['db']->getOne("select count(*) from ".DB_PREFIX."deal_focus_log where user_id = ".$user_data['id']);
                $support_count = $GLOBALS['db']->getOne("select count(*) from ".DB_PREFIX."deal_support_log where user_id = ".$user_data['id']);
    
    
                es_session::set("user_info",$user_data);
                $GLOBALS['user_info'] = $user_data;
    
                $GLOBALS['db']->query("update ".DB_PREFIX."user set login_ip = '".get_client_ip()."',login_time= ".get_gmtime().",build_count = $build_count,support_count = $support_count,focus_count = $focus_count where id =".$user_data['id']);
                return $result;
            }
        }
    }
    
    
    
    
    $m_config = getMConfig();//初始化手机端配置
    $is_weixin=isWeixin();
    //var_dump($_SERVER['REQUEST_URI']);
    if($_REQUEST['code']&&$_REQUEST['state']==1&&$m_config['wx_appid']&&$m_config['wx_secrit']&&!$GLOBALS['user_info']){
        //get_domain()."http://www.5188zc.com/wap" 为回调路径
        $weixin=new weixin($m_config['wx_appid'],$m_config['wx_secrit'],get_domain()."http://www.5188zc.com/wap");
        $wx_info=$weixin->scope_get_userinfo($_REQUEST['code']);
        if($wx_info['openid']){
            //查询该openid是否已经保存在数据库中
            $wx_user_info=get_user_has('wx_openid',$wx_info['openid']);
            //var_dump($wx_user_info);
            if($wx_user_info){
                //如果会员存在，直接登录
                do_login_user($wx_user_info['mobile'],$wx_user_info['user_pwd']);
                //跳转到个人主页
                Header("Location: http://www.5188zc.com/wap/index.php?ctl=settings");
            }else{
                //var_dump($wx_info);
                //将获取的微信用户数据存放在session中，在绑定页面保存到数据库
                es_session::set("user_wx_info",$wx_info);
                //跳转到微信账号绑定手机号页面
                Header("Location: http://www.5188zc.com/wap/index.php?ctl=user&act=wx_register");
            }
        }
    }else{
        if($is_weixin&&!$user_info&&$m_config['wx_appid']&&$m_config['wx_secrit']){
            $weixin_2=new weixin($m_config['wx_appid'],$m_config['wx_secrit'],get_domain().$_SERVER["REQUEST_URI"]);
            $wx_url=$weixin_2->scope_get_code();
            app_redirect($wx_url);
        }
    }

?>
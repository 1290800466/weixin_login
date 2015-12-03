<?php
if($GLOBALS['user_info']){
    app_redirect(url_wap("index#index"));
}
//var_dump(es_session::get('user_wx_info'));
$wx_info=es_session::get('user_wx_info');
$GLOBALS['tmpl']->assign('wx_info',$wx_info);
$GLOBALS['tmpl']->display("user_wx_register.html");



?>




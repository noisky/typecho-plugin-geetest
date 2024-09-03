<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

include 'lib/class.geetestlib.php';

/**
 * 极验验证插件，用于用户登录、用户评论时使用极验提供的滑动验证码，适配了Material主题
 *
 * @package Geetest
 * @author 小胖狐 && 饭饭 && dream2333
 * @version 1.2.2
 * @link http://zsduo.com
 * @link https://ffis.me
 * @link https://resourch.com
 */
class Geetest_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法,如果激活失败,直接抛出异常
     *
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function activate()
    {
        // 添加插件动作
        // /action/geetest?do=ajaxResponseCaptchaData
        Helper::addAction('geetest', 'Geetest_Action');

        // 注册后台底部结束钩子
        Typecho_Plugin::factory('admin/footer.php')->end = array(__CLASS__, 'renderCaptcha');

        // 注册用户登录成功钩子
        Typecho_Plugin::factory('Widget_User')->loginSucceed = array(__CLASS__, 'verifyCaptcha');

        // 评论钩子
        Typecho_Plugin::factory('Widget_Feedback')->comment = array(__CLASS__, 'commentCaptchaVerify');
        Typecho_Plugin::factory('Widget_Feedback')->trackback = array(__CLASS__, 'commentCaptchaVerify');
        Typecho_Plugin::factory('Widget_XmlRpc')->pingback = array(__CLASS__, 'commentCaptchaVerify');

        // 暴露插件函数（用于在自定义表单中渲染极验验证，以及在自定义逻辑中调用极验验证）
        Typecho_Plugin::factory('Geetest')->renderCaptcha = array(__CLASS__, 'renderCaptcha');
        Typecho_Plugin::factory('Geetest')->verifyCaptcha = array(__CLASS__, 'verifyCaptcha');
        Typecho_Plugin::factory('Geetest')->responseCaptchaData = array(__CLASS__, 'responseCaptchaData');
    }

    /**
     * 禁用插件方法,如果禁用失败,直接抛出异常
     *
     * @static
     * @access public
     * @return void
     * @throws Typecho_Plugin_Exception
     */
    public static function deactivate()
    {
        Helper::removeAction('geetest');
    }

    /**
     * 个人用户的配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form
     * @return void
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 获取插件配置面板
     *
     * @access public
     * @param Typecho_Widget_Helper_Form $form 配置面板
     * @return void
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        
        $isOpenGeetestPage = new Typecho_Widget_Helper_Form_Element_Checkbox('isOpenGeetestPage', [
            "typechoLogin" => _t('登录界面'),
            "typechoComment" => _t('评论页面')
        ], 
        array(), 
        _t('开启极验验证码的页面，勾选则开启'), 
        _t('开启评论验证码后需在主题的评论的模板 comments.php 的 form元素 中添加第一行极验的div，如当前使用的主题没有jQuery，还需在此之前引入第二行的jQuery：<textarea><div id="captcha"></div><?php Geetest_Plugin::commentCaptchaRender(); ?>&#10;<script src="https://cdn.jsdelivr.net/npm/jquery@2.2.4/dist/jquery.min.js"></script></textarea>'),);
        
        $captchaId = new Typecho_Widget_Helper_Form_Element_Text('captchaId', null, '', _t('公钥（ID）：'));
        $privateKey = new Typecho_Widget_Helper_Form_Element_Text('privateKey', null, '', _t('私钥（KEY）：'));
        $buttonSelector = new Typecho_Widget_Helper_Form_Element_Text('buttonSelector', null, '.submit', _t('评论按钮的Jquery选择器，默认为".submit"，如果第三方主题使用了自定义的css而导致无法找到按钮，可更改为对应的jq选择器'));
        $dismode = new Typecho_Widget_Helper_Form_Element_Select('dismod', array(
            'float' => '浮动式（float）',
            'embed' => '嵌入式（embed）',
            'popup' => '弹出框（popup）'
        ), 'float', _t('展现形式：'));

        $cdnUrl = new Typecho_Widget_Helper_Form_Element_Text('cdnUrl', null, '', _t('引入JS的CDN加速地址：'), _t('注意使用 https 协议<br />留空默认引入本地/static/gt.js文件，不知道的可留空'));

        $debugMode = new Typecho_Widget_Helper_Form_Element_Select('debugMode', array(
            '0' => '关闭',
            '1' => '开启'
        ), '0', _t('调试模式：'), _t('开启时，不会禁用提交按钮，用于测试插件是否生效。'));
        
        $form->addInput($isOpenGeetestPage);
        $form->addInput($captchaId);
        $form->addInput($privateKey);
        $form->addInput($buttonSelector);
        $form->addInput($dismode);
        $form->addInput($cdnUrl);
        $form->addInput($debugMode);
    }

    /**
     * 响应验证码数据
     */
    public static function responseCaptchaData()
    {
        @session_start();

        $pluginOptions = Helper::options()->plugin('Geetest');
        $geetestSdk = new GeetestLib($pluginOptions->captchaId, $pluginOptions->privateKey);

        $widgetRequest = Typecho_Widget::widget('Widget_Options')->request;
        $agent = $widgetRequest->getAgent();

        $data = array(
            'user_id' => rand(1000, 9999),
            'client_type' => self::isMobile($agent) ? 'h5' : 'web',
            'ip_address' => $widgetRequest->getIp()
        );

        $_SESSION['gt_server_ok'] = $geetestSdk->pre_process($data, 1);
        $_SESSION['gt_user_id'] = $data['user_id'];

        echo $geetestSdk->get_response_str();
    }

    /**
     * 渲染后台登陆 验证码
     */
    public static function renderCaptcha()
    {
        // 判断是否登录页面
        $widgetOptions = Typecho_Widget::widget('Widget_Options');
        $widgetRequest = $widgetOptions->request;
        $currentRequestUrl = $widgetRequest->getRequestUrl();
        if (!stripos($currentRequestUrl, 'login.php')) {
            return;
        }
        // 取出插件的配置
        $pluginOptions = Helper::options()->plugin('Geetest');
        $isOpenGeetestPage = $pluginOptions->isOpenGeetestPage;
        // 判断是否开启登陆页的验证码
        if (!in_array("typechoLogin", $isOpenGeetestPage)) {
            return;
        }
        $cdnUrl = ($pluginOptions->cdnUrl ? $pluginOptions->cdnUrl : Helper::options()->pluginUrl . '/Geetest/static/gt.min.js');
        $debugMode = (bool)($pluginOptions->debugMode);

        $disableButtonJs = '';
        $disableSubmitJs = '';
        if (!$debugMode) {
            $disableButtonJs = 'jqFormSubmit.attr({disabled:true}).addClass("gt-btn-disabled");';
            $disableSubmitJs = <<<EOF
            jqForm.submit(function (e) {
                var validate = captchaObj.getValidate();
                if (!validate) {
                    e.preventDefault();
                }
            });
EOF;
        }

        $ajaxUri = '/index.php/action/geetest?do=ajaxResponseCaptchaData';

        echo <<<EOF
        <style rel="stylesheet">
        #gt-captcha { line-height: 44px; }
        #gt-captcha .waiting { background-color: #e8e8e8; color: #4d4d4d; }
        .gt-btn-disabled { background-color: #a3b7c1!important; color: #fff!important; cursor: no-drop!important; }
        </style>
        
        <script src="{$cdnUrl}"></script>
        <script>
        
        // 获取表单提交按钮
        var jqForm = $("form");
        var jqFormSubmit = jqForm.find(":submit");
        
        // 在表单提交按钮之前添加极验验证元素
        jqFormSubmit.parent().before('<div id="gt-captcha"><p class="waiting">行为验证™ 安全组件加载中...</p></div>');
        
        // 获取极验验证元素
        var jqGtCaptcha = $("#gt-captcha");
        var jqGtCaptchaWaiting = $("#gt-captcha .waiting");
        var jqGtCaptchaNotice = $("#gt-captcha .notice");
        
        // 定义极验验证初始化回调函数
        var gtInitCallback = function (captchaObj) {
            
            captchaObj.appendTo(jqGtCaptcha);
            
            captchaObj.onSuccess(function () {
                jqFormSubmit.attr({disabled:false}).removeClass("gt-btn-disabled");
            });
            
            captchaObj.onReady(function () {
                jqGtCaptchaWaiting.remove();
                // 禁用表单提交按钮
                $disableButtonJs
            });
            
            $disableSubmitJs
        };
        
        $.ajax({
            url: "{$ajaxUri}&t=" + (new Date()).getTime(),
            type: "get",
            dataType: "json",
            success: function (data) {
                // console.log(data);
                initGeetest({
                    gt: data.gt,
                    challenge: data.challenge,
                    new_captcha: data.new_captcha,
                    product: "{$pluginOptions->dismod}",
                    offline: !data.success,
                    width: '100%'
                }, gtInitCallback);
            }
        });
        </script>
EOF;
    }

    /**
     * 渲染评论验证码
     * @throws Typecho_Plugin_Exception
     */
    public static function commentCaptchaRender() {
        //判断插件是否激活
        $options = Typecho_Widget::widget('Widget_Options');
        if (!isset($options->plugins['activated']['Geetest'])) {
            echo '<div>极验评论验证码插件未激活</div>';
            return;
        }

        // 取出插件的配置
        $pluginOptions = Helper::options()->plugin('Geetest');
        $isOpenGeetestPage = $pluginOptions->isOpenGeetestPage;
        //判断是否开启评论页的验证码
        if (!in_array("typechoComment", $isOpenGeetestPage)) {
            return;
        }
        $cdnUrl = ($pluginOptions->cdnUrl ? $pluginOptions->cdnUrl : Helper::options()->pluginUrl . '/Geetest/static/gt.min.js');
        $debugMode = (bool)($pluginOptions->debugMode);

        $disableButtonJs = '';
        $disableSubmitJs = '';
        if (!$debugMode) {
            $buttonSelector = $pluginOptions->buttonSelector;
            $disableButtonJs = '$("'.$buttonSelector.'").attr({disabled:true}).addClass("gt-btn-disabled");';
            $disableSubmitJs = <<<EOF
            $("$buttonSelector").submit(function (e) {
                var validate = captchaObj.getValidate();
                if (!validate) {
                    e.preventDefault();
                }
            });
EOF;
        }

        $ajaxUri = '/index.php/action/geetest?do=ajaxResponseCaptchaData';

        echo <<<EOF
        <style rel="stylesheet">
        #gt-captcha { line-height: 44px; }
        .gt-btn-disabled { background-color: #a3b7c1!important; color: #fff!important; cursor: no-drop!important; }
        </style>
        
        <script src="{$cdnUrl}"></script>
        <script>
            $(document).ready(function () {
                $("#captcha").append('<div id="gt-captcha"><p class="waiting">行为验证™ 安全组件加载中...</p></div>');
    
                // 获取极验验证元素
                var jqGtCaptcha = $("#gt-captcha");
                var jqGtCaptchaWaiting = $("#gt-captcha .waiting");
                var jqGtCaptchaNotice = $("#gt-captcha .notice");
                
                // 定义极验验证初始化回调函数
                var gtInitCallback = function (captchaObj) {
                    
                    captchaObj.appendTo(jqGtCaptcha);
                    
                    captchaObj.onSuccess(function () {
                        $('#comment-submit').attr({disabled:false}).removeClass("gt-btn-disabled");
                    });
                    
                    captchaObj.onReady(function () {
                        jqGtCaptchaWaiting.remove();
                        // 禁用表单提交按钮
                        $disableButtonJs
                    });
                    
                    $disableSubmitJs
                };
                
                $.ajax({
                    url: "{$ajaxUri}&t=" + (new Date()).getTime(),
                    type: "get",
                    dataType: "json",
                    success: function (data) {
                        // console.log(data);
                        initGeetest({
                            gt: data.gt,
                            challenge: data.challenge,
                            new_captcha: data.new_captcha,
                            product: "{$pluginOptions->dismod}",
                            offline: !data.success,
                            width: "200px",
                        }, gtInitCallback);
                    }
                });
            })
        </script>
EOF;
    }

    /**
     * 评论验证码 校验
     * @access public
     * @param array $comment 评论内容
     */
    public static function commentCaptchaVerify($comment)
    {
        // 取出插件的配置
        $pluginOptions = Helper::options()->plugin('Geetest');
        $isOpenGeetestPage = $pluginOptions->isOpenGeetestPage;
        //判断是否开启评论页的验证码
        if (in_array("typechoComment", $isOpenGeetestPage)) {
            if (!self::_verifyCaptcha()) {
                echo "<script language=\"JavaScript\">alert(\"验证失败，请重新验证！\");window.history.go(-1);</script>";
                exit();
            }
        }
        return $comment;

    }

    /**
     * 后台登陆验证码 校验
     */
    public static function verifyCaptcha()
    {
        //取出插件的配置
        $pluginOptions = Helper::options()->plugin('Geetest');
        $isOpenGeetestPage = $pluginOptions->isOpenGeetestPage;
        //判断是否开启评论页的验证码
        if (in_array("typechoLogin", $isOpenGeetestPage)) {
            if (!self::_verifyCaptcha()) {
                Typecho_Widget::widget('Widget_Notice')->set(_t('验证码错误'), 'error');
                Typecho_Widget::widget('Widget_User')->logout();
                Typecho_Widget::widget('Widget_Options')->response->goBack();
            }
        }
    }

    /**
     * 校验验证码 方法
     *
     * @return int
     */
    private static function _verifyCaptcha()
    {
        // 如果插件渲染失败，则默认验证不通过
        if (!isset($_POST['geetest_challenge']) || !isset($_POST['geetest_validate']) || !isset($_POST['geetest_seccode'])) {
            return 0;
        }

        @session_start();

        $pluginOptions = Helper::options()->plugin('Geetest');
        $geetestSdk = new GeetestLib($pluginOptions->captchaId, $pluginOptions->privateKey);

        if (!empty($_SESSION['gt_server_ok'])) {

            $widgetRequest = Typecho_Widget::widget('Widget_Options')->request;
            $agent = $widgetRequest->getAgent();
            $clientType = self::isMobile($agent) ? 'h5' : 'web';
            $ipAddress = $widgetRequest->getIp();

            $data = array(
                'user_id' => $_SESSION['gt_user_id'],
                'client_type' => $clientType,
                'ip_address' => $ipAddress
            );

            return $geetestSdk->success_validate($_POST['geetest_challenge'], $_POST['geetest_validate'], $_POST['geetest_seccode'], $data);
        }

        return $geetestSdk->fail_validate($_POST['geetest_challenge'], $_POST['geetest_validate'], $_POST['geetest_seccode']);
    }

    /**
     * isMobile
     *
     * @static
     * @access public
     * @return boolean
     */
    public static function isMobile($userAgent)
    {
        return preg_match('/android.+mobile|avantgo|bada\/|blackberry|blazer|compal|elaine|fennec|hiptop|iemobile|ip(hone|od)|iris|kindle|lge |maemo|midp|mmp|netfront|opera m(ob|in)i|palm( os)?|phone|p(ixi|re)\/|plucker|pocket|psp|symbian|treo|up\.(browser|link)|vodafone|wap|windows (ce|phone)|xda|xiino/i', $userAgent) || preg_match('/1207|6310|6590|3gso|4thp|50[1-6]i|770s|802s|a wa|abac|ac(er|oo|s\-)|ai(ko|rn)|al(av|ca|co)|amoi|an(ex|ny|yw)|aptu|ar(ch|go)|as(te|us)|attw|au(di|\-m|r |s )|avan|be(ck|ll|nq)|bi(lb|rd)|bl(ac|az)|br(e|v)w|bumb|bw\-(n|u)|c55\/|capi|ccwa|cdm\-|cell|chtm|cldc|cmd\-|co(mp|nd)|craw|da(it|ll|ng)|dbte|dc\-s|devi|dica|dmob|do(c|p)o|ds(12|\-d)|el(49|ai)|em(l2|ul)|er(ic|k0)|esl8|ez([4-7]0|os|wa|ze)|fetc|fly(\-|_)|g1 u|g560|gene|gf\-5|g\-mo|go(\.w|od)|gr(ad|un)|haie|hcit|hd\-(m|p|t)|hei\-|hi(pt|ta)|hp( i|ip)|hs\-c|ht(c(\-| |_|a|g|p|s|t)|tp)|hu(aw|tc)|i\-(20|go|ma)|i230|iac( |\-|\/)|ibro|idea|ig01|ikom|im1k|inno|ipaq|iris|ja(t|v)a|jbro|jemu|jigs|kddi|keji|kgt( |\/)|klon|kpt |kwc\-|kyo(c|k)|le(no|xi)|lg( g|\/(k|l|u)|50|54|\-[a-w])|libw|lynx|m1\-w|m3ga|m50\/|ma(te|ui|xo)|mc(01|21|ca)|m\-cr|me(di|rc|ri)|mi(o8|oa|ts)|mmef|mo(01|02|bi|de|do|t(\-| |o|v)|zz)|mt(50|p1|v )|mwbp|mywa|n10[0-2]|n20[2-3]|n30(0|2)|n50(0|2|5)|n7(0(0|1)|10)|ne((c|m)\-|on|tf|wf|wg|wt)|nok(6|i)|nzph|o2im|op(ti|wv)|oran|owg1|p800|pan(a|d|t)|pdxg|pg(13|\-([1-8]|c))|phil|pire|pl(ay|uc)|pn\-2|po(ck|rt|se)|prox|psio|pt\-g|qa\-a|qc(07|12|21|32|60|\-[2-7]|i\-)|qtek|r380|r600|raks|rim9|ro(ve|zo)|s55\/|sa(ge|ma|mm|ms|ny|va)|sc(01|h\-|oo|p\-)|sdk\/|se(c(\-|0|1)|47|mc|nd|ri)|sgh\-|shar|sie(\-|m)|sk\-0|sl(45|id)|sm(al|ar|b3|it|t5)|so(ft|ny)|sp(01|h\-|v\-|v )|sy(01|mb)|t2(18|50)|t6(00|10|18)|ta(gt|lk)|tcl\-|tdg\-|tel(i|m)|tim\-|t\-mo|to(pl|sh)|ts(70|m\-|m3|m5)|tx\-9|up(\.b|g1|si)|utst|v400|v750|veri|vi(rg|te)|vk(40|5[0-3]|\-v)|vm40|voda|vulc|vx(52|53|60|61|70|80|81|83|85|98)|w3c(\-| )|webc|whit|wi(g |nc|nw)|wmlb|wonu|x700|yas\-|your|zeto|zte\-/i', substr($userAgent, 0, 4));
    }

}
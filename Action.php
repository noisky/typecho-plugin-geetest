<?php

/**
 * 极验验证插件执行
 */
class Geetest_Action extends Typecho_Widget implements Widget_Interface_Do
{
    public function execute()
    {
    }

    public function action()
    {
        $this->on($this->request->is('do=ajaxResponseCaptchaData'))->ajaxResponseCaptchaData();
    }

    public function ajaxResponseCaptchaData()
    {
        if (!$this->request->isAjax()) {
            $this->response->redirect('/');
        }

        // 防止 PHP 诊断信息导致前端 JSON 解析失败
        $this->response->setContentType('application/json');
        Typecho_Plugin::factory('Geetest')->responseCaptchaData();
    }
}

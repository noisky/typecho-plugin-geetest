<?php

/**
 * 极验行为式验证安全平台，php 网站主后台包含的库文件
 *
 * @author Tanxu
 */
class GeetestLib {
    const GT_SDK_VERSION = 'php_3.0.0';

    public static $connectTimeout = 1;
    public static $socketTimeout  = 1;

    // 显式声明属性，避免 PHP 8.2+ 动态属性弃用警告
    private $captcha_id;
    private $private_key;
    private $response;

    public function __construct($captcha_id, $private_key) {
        $this->captcha_id  = $captcha_id;
        $this->private_key = $private_key;
    }

    /**
     * 判断极验服务器是否down机
     *
     * @param array $data
     * @return int
     */
    public function pre_process($param, $new_captcha=1) {
        $data = array('gt'=>$this->captcha_id,
                     'new_captcha'=>$new_captcha
                );
        $data = array_merge($data,$param);
        $query = http_build_query($data);
        $url = "http://api.geetest.com/register.php?" . $query;
        $challenge = $this->send_request($url);
        // 接口异常时切换到极验离线模式
        if (!is_string($challenge) || strlen($challenge) !== 32) {
            $this->failback_process();
            return 0;
        }
        $this->success_process($challenge);
        return 1;
    }

    /**
     * @param $challenge
     */
    private function success_process($challenge) {
        $challenge      = md5($challenge . $this->private_key);
        $result         = array(
            'success'   => 1,
            'gt'        => $this->captcha_id,
            'challenge' => $challenge,
            'new_captcha'=>1
        );
        $this->response = $result;
    }

    /**
     *
     */
    private function failback_process() {
        $rnd1           = md5(rand(0, 100));
        $rnd2           = md5(rand(0, 100));
        $challenge      = $rnd1 . substr($rnd2, 0, 2);
        $result         = array(
            'success'   => 0,
            'gt'        => $this->captcha_id,
            'challenge' => $challenge,
            'new_captcha'=>1
        );
        $this->response = $result;
    }

    /**
     * @return mixed
     */
    public function get_response_str() {
        return json_encode($this->response);
    }

    /**
     * 返回数组方便扩展
     *
     * @return mixed
     */
    public function get_response() {
        return $this->response;
    }

    /**
     * 正常模式获取验证结果
     *
     * @param string $challenge
     * @param string $validate
     * @param string $seccode
     * @param array $param
     * @return int
     */
    public function success_validate($challenge, $validate, $seccode,$param, $json_format=1) {
        // 拒绝非字符串参数，避免 PHP 8.x 类型错误
        if (!is_string($challenge) || !is_string($validate) || !is_string($seccode)) {
            return 0;
        }

        if (!$this->check_validate($challenge, $validate)) {
            return 0;
        }
        $query = array(
            "seccode" => $seccode,
            "timestamp"=>time(),
            "challenge"=>$challenge,
            "captchaid"=>$this->captcha_id,
            "json_format"=>$json_format,
            "sdk"     => self::GT_SDK_VERSION
        );
        $query = array_merge($query,$param);
        $url          = "http://api.geetest.com/validate.php";
        $codevalidate = $this->post_request($url, $query);
        // 极验返回异常或非 JSON 时，验证直接失败
        if (!is_string($codevalidate) || $codevalidate === '') {
            return 0;
        }

        $obj = json_decode($codevalidate, true);
        if (!is_array($obj) || !isset($obj['seccode']) || !is_string($obj['seccode'])) {
            return 0;
        }

        // 使用时序安全比较，避免直接比较验证码摘要
        if (hash_equals(md5($seccode), $obj['seccode'])) {
            return 1;
        }

        return 0;
    }

    /**
     * 宕机模式获取验证结果
     *
     * @param $challenge
     * @param $validate
     * @param $seccode
     * @return int
     */
    public function fail_validate($challenge, $validate, $seccode) {
        if (!is_string($challenge) || !is_string($validate) || !is_string($seccode)) {
            return 0;
        }

        // 离线模式使用挑战值摘要完成本地校验
        if (hash_equals(md5($challenge), $validate)) {
            return 1;
        }

        return 0;
    }

    /**
     * @param $challenge
     * @param $validate
     * @return bool
     */
    private function check_validate($challenge, $validate) {
        if (!is_string($challenge) || !is_string($validate) || strlen($validate) !== 32) {
            return false;
        }

        // 使用时序安全比较校验正常模式签名
        if (!hash_equals(md5($this->private_key . 'geetest' . $challenge), $validate)) {
            return false;
        }

        return true;
    }

    /**
     * GET 请求
     *
     * @param $url
     * @return mixed|string
     */
    private function send_request($url) {

        if (function_exists('curl_exec')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::$connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::$socketTimeout);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            // 先执行请求，再读取 cURL 错误状态
            $data = curl_exec($ch);
            $curl_errno = curl_errno($ch);

            // PHP 8.5 中 curl_close() 已弃用且无实际作用
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
            if ($curl_errno > 0 || $data === false) {
                return 0;
            }else{
                return $data;
            }
        } else {
            $opts    = array(
                'http' => array(
                    'method'  => "GET",
                    'timeout' => self::$connectTimeout + self::$socketTimeout,
                )
            );
            $context = stream_context_create($opts);
            $data    = @file_get_contents($url, false, $context);
            if($data){ 
                return $data;
            }else{ 
                return 0;
            } 
        }
    }

    /**
     *
     * @param       $url
     * @param array $postdata
     * @return mixed|string
     */
    private function post_request($url, $postdata = '') {
        if (!$postdata) {
            return false;
        }

        $data = http_build_query($postdata);
        if (function_exists('curl_exec')) {
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::$connectTimeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, self::$socketTimeout);

            //不可能执行到的代码
            if (!$postdata) {
                curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['HTTP_USER_AGENT']);
            } else {
                curl_setopt($ch, CURLOPT_POST, 1);
                curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            }
            $data = curl_exec($ch);

            if (curl_errno($ch)) {
                $err = sprintf("curl[%s] error[%s]", $url, curl_errno($ch) . ':' . curl_error($ch));
                $this->triggerError($err);
            }

            // PHP 8.5 中 curl_close() 已弃用且无实际作用
            if (PHP_VERSION_ID < 80500) {
                curl_close($ch);
            }
        } else {
            if ($postdata) {
                $opts    = array(
                    'http' => array(
                        'method'  => 'POST',
                        'header'  => "Content-type: application/x-www-form-urlencoded\r\n" . "Content-Length: " . strlen($data) . "\r\n",
                        'content' => $data,
                        'timeout' => self::$connectTimeout + self::$socketTimeout
                    )
                );
                $context = stream_context_create($opts);
                $data    = file_get_contents($url, false, $context);
            }
        }

        return $data;
    }


    
    /**
     * @param $err
     */
    private function triggerError($err) {
        // 错误写入日志，避免污染 Geetest JSON 响应
        error_log($err);
    }
}

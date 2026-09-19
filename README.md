## Geetest for Typecho

极验验证插件，为 Typecho 后台登录和前台评论提供极验行为验证（滑动验证码）。

评论验证码需要在主题的 `comments.php` 中手动渲染，Material 主题可以直接使用。

### 功能

- 支持在 Typecho 登录页和主题评论页使用极验验证码。
- 评论端通过 `commentCaptchaRender()` 自动识别评论表单和提交按钮，也支持使用 `data-geetest-form`、`data-geetest-submit` 进行自定义配置。
- 兼容 jQuery 延迟加载和 PJAX，验证码初始化前会阻止表单提交，验证成功后恢复提交。
- 增加提交中状态和重复提交防护；验证码加载失败时显示重试提示。
- 提供调试模式，可跳过前端验证码拦截，但服务端校验仍然有效。
- 根据 Typecho 站点地址生成验证码接口，支持子目录、启用伪静态或保留 `index.php` 的部署方式。
- 兼容 PHP 8.2 及以上版本，并避免 PHP 8.5 中已弃用的 cURL 关闭调用。

### 安装

下载插件后解压，并将目录名改为 `Geetest`，上传到 Typecho 的 `usr/plugins` 目录，在后台插件面板启用并配置。

也可以直接执行：

```bash
cd typechoPath/usr/plugins
git clone https://github.com/noisky/typecho-plugin-geetest.git Geetest
```

### 配置伪静态

插件会根据 Typecho 的站点地址自动生成 `action/geetest?do=ajaxResponseCaptchaData` 接口地址，不需要手动填写路径。站点仍需确保 Typecho Action 路由可访问；使用 Nginx 伪静态时可参考以下配置：

```nginx
location / {
    if (!-e $request_filename) {
        rewrite ^(.*)$ /index.php;
    }
}
```

宝塔面板可以在站点设置中配置伪静态。Apache 的重写写法不同，请按服务器环境配置。

### 插件配置项

在 Typecho 后台进入插件配置页：

| 配置项 | 说明 |
| --- | --- |
| 开启极验验证码的页面 | 可分别勾选“登录界面”和“评论页面”。 |
| 公钥（ID） | 在极验平台创建应用后取得的 `captchaId`。 |
| 私钥（KEY） | 与公钥对应的服务端密钥，不能暴露给前端。 |
| 展现形式 | `float` 浮动式、`embed` 嵌入式或 `popup` 弹出框。默认是浮动式。 |
| 引入 JS 的 CDN 加速地址 | 填写极验前端脚本地址，建议使用 HTTPS；留空时使用插件自带的 `static/gt.min.js`。 |
| 调试模式 | 默认关闭；开启后跳过验证码前端校验拦截，初始化时不会禁用提交按钮；提交时仍保留防重复提交锁，服务端校验仍然有效。 |

公钥和私钥需要在 [极验官网](https://www.geetest.com/) 获取。服务端还需要能访问极验接口，并且 PHP Session 可正常工作；验证码初始化与提交校验必须使用同一个 Session。

### 评论验证码集成

#### 基础用法

在主题的 `comments.php` 中，将验证码容器和渲染调用放在评论表单内部：

```php
<form id="comment_form" method="post">
    <!-- 评论者信息、评论正文等字段 -->

    <div id="captcha"></div>
    <?php Geetest_Plugin::commentCaptchaRender(); ?>

    <button type="submit">提交评论</button>
</form>
```

注意：

- 容器 ID 必须是 `captcha`，并且默认需要位于目标评论 `<form>` 内。
- 插件使用该表单中的第一个提交控件进行禁用、启用和提交校验。
- 评论页需要 jQuery。Material 主题已经加载 jQuery，其他主题请确保页面中已有 jQuery。

#### 指定表单和提交按钮

如果验证码容器不在表单内，或主题中存在多个表单、多个提交按钮，可以使用 CSS 选择器显式指定：

```html
<form id="comment_form" method="post">
    <div id="captcha"
         data-geetest-form="#comment_form"
         data-geetest-submit="#send-comment"></div>
    <button id="send-comment" type="submit">提交评论</button>
</form>
<?php Geetest_Plugin::commentCaptchaRender(); ?>
```

`data-geetest-form` 指定评论表单，`data-geetest-submit` 指定提交控件。未设置这些属性时，插件会自动查找；如果已经设置但选择器无效或没有匹配元素，页面会显示“未找到评论表单或提交按钮”，此时请修正选择器。

#### 评论失败回退协议（prg-v1）

**为什么需要？**

验证码失败后，插件需要回到原来的评论页，同时显示错误信息并保留用户刚才填写的内容。`prg-v1` 约定了统一的返回和提示方式，因此 Geetest、SmartSpam 等评论插件可以使用同一套错误样式。

这个协议只处理失败后的返回和提示，不会跳过服务端验证码校验。未接入协议的主题仍使用 Typecho 默认错误处理。

**如何接入？**

在评论表单 `<form>` 内加入下面两行：

```html
<input type="hidden" name="comment_error_protocol" value="prg-v1">
<input type="hidden" name="comment_error_anchor" value="comment_form">
```

`comment_error_anchor` 填写评论表单的 `id`，不要写 `#`。上面的示例会在失败后返回到 `#comment_form`。

添加这两行后，验证码失败时不会进入 Typecho 默认失败页。

插件会返回评论页，并把错误信息写入一次性的 `__typecho_comment_error` Cookie；主题可以自行读取这个 Cookie 并展示错误提示。

Material 主题已经处理好这一步，无需重复添加。其他主题可参考下方代码，获取并显示错误内容：

```php
<?php
$commentError = Typecho_Cookie::get('__typecho_comment_error', '');
if ('' !== $commentError) {
    Typecho_Cookie::delete('__typecho_comment_error');
    echo '<div class="alert alert-danger comment-error" role="alert">'
        . htmlspecialchars($commentError, ENT_QUOTES, 'UTF-8')
        . '</div>';
}
?>
```

### 登录验证码

勾选“登录界面”后，插件会自动向登录表单加入验证码；登录失败时由 Typecho 默认通知显示错误。

### 验证范围与请求要求

勾选“评论页面”后，普通评论的服务端会检查 `geetest_challenge`、`geetest_validate` 和 `geetest_seccode`，缺少任一字段都会验证失败。Trackback 和 Pingback 不提交这些字段，因此不参与 Geetest 校验。

验证码初始化失败时，请优先检查浏览器网络面板中的 `action/geetest` 请求、PHP 错误日志、Session 写入权限，以及服务器到极验接口的网络连通性。

### 截图

**插件设置**

![插件配置范例图](https://cdn.jsdelivr.net/gh/noisky/typecho-plugin-geetest@master/images/setting_page.jpg)

**后台登录验证码**

![后台登录验证码范例](https://cdn.jsdelivr.net/gh/noisky/typecho-plugin-geetest@master/images/login_page.jpg)

**评论验证码**

![评论验证码范例](https://cdn.jsdelivr.net/gh/noisky/typecho-plugin-geetest@master/images/comment_page.png)

### 致谢

- 原作者 [@zhb127](https://github.com/zhb127/typecho-plugin-geetest) 
- fork 自 [@xueshanlinghu](https://github.com/xueshanlinghu/typecho-plugin-geetest)
- 小胖狐 贡献者
- @CairBin 贡献者
- 该分支目前由饭饭维护

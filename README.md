## Geetest for Typecho

极验验证插件，为 Typecho 后台登录和前台评论提供极验行为验证（滑动验证码）。

插件保留后台登录验证，并支持在主题评论模板中手动渲染评论验证码；评论端已适配 PJAX 主题，Material 主题可以直接使用。


### 功能与改动

- 支持在 Typecho 登录页和主题评论页使用极验验证码。
- 评论端通过 `commentCaptchaRender()` 自动识别评论表单和提交按钮，也支持使用 `data-geetest-form`、`data-geetest-submit` 进行自定义配置。
- 兼容 jQuery 延迟加载和 PJAX，验证码初始化前会阻止表单提交，验证成功后恢复提交。
- 增加提交中状态和重复提交防护；验证码加载失败时显示重试提示。
- 验证失败后通过 POST/Redirect/GET 返回评论页，显示一次性错误提示并恢复评论内容。
- 提供调试模式，可跳过前端验证码拦截，但服务端校验仍然有效。

### 安装

下载插件后解压，并将目录名改为 `Geetest`，上传到 Typecho 的 `usr/plugins` 目录，在后台插件面板启用并配置。

也可以直接执行：

```bash
cd typechoPath/usr/plugins
git clone https://github.com/noisky/typecho-plugin-geetest.git Geetest
```

### 配置伪静态

插件通过 `/index.php/action/geetest?do=ajaxResponseCaptchaData` 获取验证码初始化数据。站点需要能够正常访问 Typecho 的 Action 路由；使用 Nginx 时可参考以下配置：

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
| 调试模式 | 默认关闭；开启后跳过验证码前端校验拦截，服务端校验仍然有效。当前实现初始化时仍会设置提交按钮为 `disabled`，便于测试时请注意这一实际行为。 |

公钥和私钥需要在[极验官网](https://www.geetest.com/)获取。服务端还需要能访问极验接口，并且 PHP Session 可正常工作。

配置页中的评论模板说明是面向通用主题的示例，会同时展示 jQuery 引入代码；如果主题（例如 Material）已经加载 jQuery，不要重复引入。

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
- 评论页需要 jQuery。Material 主题会在 `footer.php` 中统一加载本地 jQuery，不需要在 `comments.php` 中重复引入。
- 其他主题如果尚未加载 jQuery，请在页面完成解析前引入。

插件会在 DOM 解析完成且检测到 `window.jQuery` 后初始化验证码；如果始终没有 jQuery，验证码不会完成初始化，服务端也会拒绝缺少验证字段的请求。jQuery 不要求严格先于 `commentCaptchaRender()` 执行，插件会等待它出现；提前加载只是为了减少等待和异步加载竞态。

提交通过前端校验后，插件会将提交按钮设置为禁用状态并添加 `gt-btn-loading`，显示等待光标和旋转提示，同时给评论表单设置 `aria-busy="true"`。验证码重新初始化时会移除加载状态和 `aria-busy`，再重新绑定表单提交事件。

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

#### 验证失败后的返回位置

默认情况下，验证失败后返回并定位到 `#captcha`。如果希望定位到整个评论表单，可在表单中加入以下隐藏字段：

```html
<input type="hidden" name="geetest_return_anchor" value="comment_form">
```

插件只接受 `comment_form` 这一固定值，此时返回锚点为 `#comment_form`；未提供或使用其他值时仍返回 `#captcha`。失败提示由 `commentCaptchaRender()` 输出一次后立即清除，评论正文则写入 Typecho 的记忆 Cookie，供返回页面恢复。

### 登录验证码

勾选“登录界面”后，插件会通过 `admin/footer.php` 钩子判断当前请求是否为 `login.php`，并自动向登录表单注入验证码。登录失败时由 Typecho 后台通知显示“验证码错误”，同时注销当前用户并返回登录页。

登录页脚本使用 jQuery 初始化验证码，请确保自定义后台登录页面没有移除 jQuery。

### 验证范围与请求要求

勾选“评论页面”后，插件会挂接 Typecho 的评论、Trackback 和 Pingback 钩子，并要求请求包含以下三个验证码字段：

```text
geetest_challenge
geetest_validate
geetest_seccode
```

缺少任一字段都会被判定为验证失败。若站点依赖非浏览器提交的 Trackback 或 Pingback，请在启用评论验证码后确认这些调用方能够兼容该验证流程。

### 目录结构

```text
Geetest/
├── Plugin.php                 # 插件配置、渲染和服务端校验
├── Action.php                 # /action/geetest Ajax Action
├── lib/class.geetestlib.php   # 极验 PHP SDK
├── static/gt.js               # 极验前端脚本
├── static/gt.min.js           # 默认加载的压缩脚本
├── images/                    # README 配置及效果截图
└── LICENSE
```

### 截图

**插件设置**

![插件配置范例图](https://cdn.jsdelivr.net/gh/noisky/typecho-plugin-geetest@master/images/setting_page.jpg)

**后台登录验证码**

![后台登录验证码范例](https://cdn.jsdelivr.net/gh/noisky/typecho-plugin-geetest@master/images/login_page.jpg)

**评论验证码**

![评论验证码范例](https://cdn.jsdelivr.net/gh/noisky/typecho-plugin-geetest@master/images/comment_page.png)

### Thanks

- @zhb127
- @xueshanlinghu
- 小胖狐
- @CairBin

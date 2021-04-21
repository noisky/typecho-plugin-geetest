## Geetest for Typecho

极验验证插件，用于用户登录、用户评论时使用极验提供的滑动验证码，适配了Material主题

### 更新说明
保留原插件的登陆验证功能，新增评论验证功能

### 使用方法

#### 1 下载激活插件
下载插件后，解压，将文件夹名称改为 Geetest，上传到 /usr/plugins 目录下，在插件面板启用插件并配置即可使用；

或者直接在 Typecho 的插件目录下执行如下命令：
```
cd typechoPath/usr/plugins
git clone https://github.com/noisky/typecho-plugin-geetest.git Geetest
```

#### 2 配置插件
极验验证码的 ID 和 KEY 需要到极验官网 `https://www.geetest.com/` 获取；

注册、创建应用的时候，基础版是免费的；

如需开启评论验证码，则需要在你的主题评论模板 `comment.php` 中的任意一行添加如下代码：
```
<div id="captcha"></div><?php Geetest_Plugin::commentCaptchaRender(); ?>
<script src="https://cdn.jsdelivr.net/npm/jquery@2.2.4/dist/jquery.min.js"></script>
```
该功能需要JQuery插件支持，如果主题已经集成则不用引入该插件。

**插件设置**

![插件配置范例图](https://cdn.jsdelivr.net/gh/noisky/typecho-plugin-geetest@master/images/setting_page.jpg)

**后台登录验证码**

![后台登录验证码范例](https://cdn.jsdelivr.net/gh/noisky/typecho-plugin-geetest@master/images/login_page.jpg)

**评论验证码**

![评论验证码范例](https://cdn.jsdelivr.net/gh/noisky/typecho-plugin-geetest@master/images/comment_page.png)

### Thanks

@zhb127

@xueshanlinghu 

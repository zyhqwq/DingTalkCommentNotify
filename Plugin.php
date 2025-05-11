<?php
/**
 * 钉钉评论通知插件 - 修正时间版本
 * 
 * @package DingTalkCommentNotify
 * @author 桦哲
 * @version 1.1.0
 * @link https://web.zyhmifan.top/
 */
class DingTalkCommentNotify_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件
     */
    public static function activate()
    {
        // 设置默认时区（如果服务器未正确配置）
        if (function_exists('date_default_timezone_set') && !ini_get('date.timezone')) {
            date_default_timezone_set('Asia/Shanghai');
        }
        
        Typecho_Plugin::factory('Widget_Feedback')->comment = array(__CLASS__, 'sendDingTalkNotify');
        Typecho_Plugin::factory('Widget_Feedback')->trackback = array(__CLASS__, 'sendDingTalkNotify');
        
        return _t('钉钉评论通知插件已激活，时间问题已修正');
    }

    /**
     * 禁用插件
     */
    public static function deactivate()
    {
        return _t('钉钉评论通知插件已禁用');
    }

    /**
     * 插件配置面板
     * 
     * @param Typecho_Widget_Helper_Form $form
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        // Webhook地址
        $webhook = new Typecho_Widget_Helper_Form_Element_Text(
            'webhook', 
            NULL, 
            NULL, 
            _t('钉钉机器人Webhook地址'), 
            _t('格式：https://oapi.dingtalk.com/robot/send?access_token=xxxxxxxx')
        );
        $webhook->addRule('required', _t('Webhook地址不能为空'));
        $webhook->addRule('url', _t('请输入有效的URL地址'));
        $form->addInput($webhook);

        // 加签密钥
        $secret = new Typecho_Widget_Helper_Form_Element_Text(
            'secret', 
            NULL, 
            NULL, 
            _t('钉钉机器人加签密钥'), 
            _t('如果机器人设置了加签，请填写此处')
        );
        $form->addInput($secret);

        // 时区设置
        $timezone = new Typecho_Widget_Helper_Form_Element_Select(
            'timezone',
            array(
                'Asia/Shanghai' => '中国标准时间 (UTC+8)',
                'Asia/Tokyo' => '日本时间 (UTC+9)',
                'America/New_York' => '美国东部时间 (UTC-5)',
                'Europe/London' => '伦敦时间 (UTC+0)'
            ),
            'Asia/Shanghai',
            _t('时区设置'),
            _t('请选择您的服务器所在时区')
        );
        $form->addInput($timezone);

        // @手机号设置
        $atMobiles = new Typecho_Widget_Helper_Form_Element_Text(
            'atMobiles', 
            NULL, 
            NULL, 
            _t('需要@的手机号'), 
            _t('多个手机号用英文逗号分隔，如：13800138000,13900139000')
        );
        $form->addInput($atMobiles);

        // 是否@所有人
        $atAll = new Typecho_Widget_Helper_Form_Element_Radio(
            'atAll', 
            array(
                '0' => _t('否'),
                '1' => _t('是')
            ), 
            '0', 
            _t('是否@所有人')
        );
        $form->addInput($atAll);
    }

    /**
     * 个人用户的配置面板
     */
    public static function personalConfig(Typecho_Widget_Helper_Form $form){}

    /**
     * 发送钉钉通知
     */
    public static function sendDingTalkNotify($comment, $post)
    {
        $options = Typecho_Widget::widget('Widget_Options');
        $pluginOptions = $options->plugin('DingTalkCommentNotify');
        
        // 获取配置
        $webhook = $pluginOptions->webhook;
        $secret = $pluginOptions->secret;
        $timezone = $pluginOptions->timezone;
        $atMobiles = $pluginOptions->atMobiles;
        $atAll = $pluginOptions->atAll;
        
        // 处理时间显示
        try {
            $dt = new DateTime('@' . $comment['created']);
            $dt->setTimezone(new DateTimeZone($timezone));
            $formattedTime = $dt->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            $formattedTime = date('Y-m-d H:i:s', $comment['created']);
        }

        // 构建消息内容
        $title = "博客有新评论";
        $text = "**评论人**: {$comment['author']}\n\n";
        $text .= "**评论文章**: [{$post->title}]({$post->permalink})\n\n";
        $text .= "**评论内容**: \n\n > {$comment['text']}\n\n";
        $text .= "**评论时间**: {$formattedTime}";
        
        // 构建@信息
        $at = array();
        if ($atAll) {
            $at['isAtAll'] = true;
        } elseif (!empty($atMobiles)) {
            $at['atMobiles'] = array_map('trim', 
                explode(',', str_replace('，', ',', $atMobiles)));
        }
        
        // 构建请求数据
        $data = array(
            'msgtype' => 'markdown',
            'markdown' => array(
                'title' => $title,
                'text' => $text
            ),
            'at' => $at
        );
        
        // 如果有加签密钥，计算签名
        $timestamp = time() * 1000;
        if (!empty($secret)) {
            $sign = urlencode(base64_encode(hash_hmac('sha256', $timestamp . "\n" . $secret, $secret, true)));
            $webhook .= (strpos($webhook, '?') === false ? '?' : '&') . "timestamp={$timestamp}&sign={$sign}";
        }
        
        // 发送请求
        $ch = curl_init();
        curl_setopt_array($ch, array(
            CURLOPT_URL => $webhook,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_HTTPHEADER => array('Content-Type: application/json'),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false
        ));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        // 记录错误日志（可选）
        if ($httpCode != 200) {
            file_put_contents(__DIR__ . '/dingtalk_notify_error.log', 
                date('Y-m-d H:i:s') . " - HTTP {$httpCode} - Response: {$response}\n",
                FILE_APPEND);
        }

        return $comment;
    }
}
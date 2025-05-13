<?php
if (!defined('__TYPECHO_ROOT_DIR__')) exit;

/**
 * <strong style="color:#28B7FF;font-family: 楷体;">使用企业微信机器人推送评论通知</strong>
 * 
 * @package QYWXCommentPush 
 * @author <strong style="color:#28B7FF;font-family: 楷体;">Busy</strong>
 * @version 1.0.0
 * @link https://github.com/Busy0769/QYWXCommentPush
 */
class QYWXCommentPush_Plugin implements Typecho_Plugin_Interface
{
    /**
     * 激活插件方法
     */
    public static function activate()
    {
        Typecho_Plugin::factory('Widget_Feedback')->comment = array('QYWXCommentPush_Plugin', 'pushComment');
        Typecho_Plugin::factory('Widget_Comments_Edit')->finishComment = array('QYWXCommentPush_Plugin', 'pushComment');
        return _t('插件已经激活，请设置企业微信机器人Webhook地址');
    }
    
    /**
     * 禁用插件方法
     */
    public static function deactivate()
    {
        return _t('插件已被禁用');
    }
    
    /**
     * 配置面板
     */
    public static function config(Typecho_Widget_Helper_Form $form)
    {
        $webhook = new Typecho_Widget_Helper_Form_Element_Text(
            'webhookUrl', 
            NULL,
            '',
            _t('企业微信机器人Webhook地址'),
            _t('请输入企业微信机器人的Webhook地址，格式为：https://qyapi.weixin.qq.com/cgi-bin/webhook/send?key=xxxxxxxx')
        );
        $form->addInput($webhook->addRule('required', _t('Webhook地址不能为空')));
    }

    public static function personalConfig(Typecho_Widget_Helper_Form $form)
    {
    }

    /**
     * 发送请求到企业微信
     */
    private static function sendRequest($data, $webhookUrl)
    {
        try {
            $ch = curl_init($webhookUrl);
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data, JSON_UNESCAPED_UNICODE));
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            
            if ($response === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new Exception('请求失败: ' . $error);
            }
            
            curl_close($ch);
            
            $result = json_decode($response, true);
            if ($httpCode != 200 || $result['errcode'] != 0) {
                throw new Exception('推送失败: ' . ($result['errmsg'] ?? '未知错误'));
            }
            
            error_log('企业微信推送成功');
            return $result;
            
        } catch (Exception $e) {
            error_log('企业微信推送异常: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * 构造并推送评论消息
     */
    public static function pushComment($comment, $widget)
    {
        $db = Typecho_Db::get();
        $post = $db->fetchRow($db->select('title')
            ->from('table.contents')
            ->where('cid = ?', $comment['cid']));
        
        // 构建推送内容
        $content = "新的博客评论通知\n\n".
                   "--文章标题：{$post['title']}\n\n".
                   "--评论用户：{$comment['author']}\n\n".
                   "--评论时间：".date('Y-m-d H:i:s', $comment['created'])."\n\n".
                   "--评论内容：\n{$comment['text']}";
        
		//推送内容字节数限制，企业微信文本类型最大不能超过2048个字节
		$maxBytes = 2000; // 最大字节数
		$ellipsis = "..."; // 省略号（占3字节）
		
		// 1. 计算原始内容的字节长度
		$contentLength = strlen($content);
		
		// 2. 判断是否需要截取
		if ($contentLength > $maxBytes) {
		    // 截取到（最大字节数 - 省略号占用的3字节），保证总长度不超过2000
		    $truncated = mb_strcut($content, 0, $maxBytes - strlen($ellipsis), 'UTF-8');
		    $content = $truncated . $ellipsis;
		}
		
        $options = Helper::options();
        $webhookUrl = $options->plugin('QYWXCommentPush')->webhookUrl;
        
        // 企业微信消息结构
        $data = [
            "msgtype" => "text",
            "text" => [
                "content" => $content,
                //"mentioned_list" => ["@all"] // 根据需要调整@所有人
            ]
        ];
        
        self::sendRequest($data, $webhookUrl);
        
        return $comment;
    }
}
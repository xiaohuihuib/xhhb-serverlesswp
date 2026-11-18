<?php
if (!defined('WORDFENCE_VERSION')) { exit; }
$labels = array('none' => __('News', 'wordfence'), 'urgent' => __('Urgent', 'wordfence'), 'high' => __('High', 'wordfence'), 'new' => __('New', 'wordfence'));
$icons = array('news' => 'megaphone', 'alert' => 'warning', 'wordpress' => 'wordpress', 'security' => 'shield', 'trend' => 'chart-line', 'code' => 'editor-code', 'research' => 'search', 'login' => 'lock', 'plugins' => 'admin-plugins', 'learning' => 'book');
$footer = array('news/' => __('News', 'wordfence'), 'blog/' => __('Blog', 'wordfence'), 'subscribe-to-the-wordfence-email-list/' => __('Newsletter', 'wordfence'), 'learn/' => __('Security Learning Center', 'wordfence'));
?>
<section class="wf-security-news wf-security-news-<?php echo esc_attr($surface); ?> wf-security-news-<?php echo esc_attr($layout); ?>" aria-label="<?php esc_attr_e('Wordfence Security News', 'wordfence'); ?>">
	<?php if ($surface === 'wf'): ?>
	<header class="wf-security-news-header">
		<h2><?php esc_html_e('Wordfence Security News', 'wordfence'); ?></h2>
		<a class="wf-security-news-blog-link" href="<?php echo esc_url(wfSecurityNews::attributedURL('https://www.wordfence.com/blog/', $surface)); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e('Read more on the Wordfence Blog', 'wordfence'); ?><span class="dashicons dashicons-external" aria-hidden="true"></span><span class="screen-reader-text"> <?php esc_html_e('(opens in new tab)', 'wordfence'); ?></span></a>
	</header>
	<?php endif; ?>
	<?php if (!$news['posts']): ?>
		<p class="wf-security-news-unavailable"><?php esc_html_e('Security news is currently unavailable.', 'wordfence'); ?></p>
	<?php else: ?>
	<ul class="wf-security-news-posts">
		<?php foreach ($news['posts'] as $post): ?>
		<li class="wf-security-news-post wf-security-news-severity-<?php echo esc_attr($post['badge']); ?>">
			<div class="wf-security-news-copy">
				<div class="wf-security-news-meta">
					<span class="wf-security-news-icon dashicons dashicons-<?php echo esc_attr($icons[$post['icon']]); ?>" aria-hidden="true"></span>
					<span class="wf-security-news-badge<?php echo $post['badge'] === 'none' ? ' screen-reader-text' : ''; ?>"><?php echo esc_html($labels[$post['badge']]); ?></span>
				</div>
				<a class="wf-security-news-title" href="<?php echo esc_url(wfSecurityNews::attributedURL($post['url'], $surface)); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($post['title']); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in new tab)', 'wordfence'); ?></span></a>
				<p class="wf-security-news-excerpt"><?php echo esc_html($post['excerpt']); ?></p>
				<time datetime="<?php echo esc_attr(gmdate('c', $post['published_at'])); ?>"><?php echo esc_html(date_i18n(get_option('date_format'), $post['published_at'] + wfUtils::timeZoneMinutes($post['published_at']) * MINUTE_IN_SECONDS)); ?></time>
			</div>
		</li>
		<?php endforeach; ?>
	</ul>
	<?php endif; ?>
	<footer class="wf-security-news-footer">
		<?php foreach ($footer as $path => $label): ?>
		<a href="<?php echo esc_url(wfSecurityNews::attributedURL('https://www.wordfence.com/' . $path, $surface)); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html($label); ?><span class="screen-reader-text"> <?php esc_html_e('(opens in new tab)', 'wordfence'); ?></span></a>
		<?php endforeach; ?>
	</footer>
</section>

<?php
require(EC_PATH . '/includes/init.php');

/**
 * 获取所有推送的消息
 */
if ($tmp[0] == 'noticeList') {

    $cat_id = _POST('cat_id', 0);
    if (empty($cat_id)) {
        GZ_Api::outPut(101);
    }

    $rel = $dbi->where('cat_id', $cat_id)->getValue('article', 'COUNT(*)');

    GZ_Api::outPut(array('sum'=>$rel));
}


$id = _POST('article_id', 0);
if (empty($id)) {
    GZ_Api::outPut(101);
}

/**
 * 文章点赚
 */
if ($_POST['target'] == 'addGood'){
    $user_id = $_POST['session']['uid'];
    $count = $dbi->where('article_id', $id)->where('uid', $user_id)->where(today_sql('add_good_time'))->getValue('add_good', 'COUNT(*)');
	if($count > 0){
		GZ_Api::outPut(12);
	}

    $dbi->insert ('add_good', array(
        'article_id' => $id,
        'uid' => $user_id,
        'status' => 1,
        'add_good_time' => gmtime()
    ));

    $dbi->where('article_id', $id)->update ('article', array('good_nums' => $dbi->inc()));

    $addGood_back = array('freeback' => '点赞成功');
    GZ_API::outPut(array('data' => $addGood_back));
}


if (!$article = $dbi->where('article_id', $id)->where('is_open', 1)->getOne('article', 'article_id as id, title, content, author, description, add_time, read_nums, good_nums')) {
    GZ_Api::outPut(13);
}
$article['add_time'] = local_date("Y-m-d H:i", $article['add_time']);

/* 统计阅读量 */
$dbi->where('article_id', $id)->update ('article', array('read_nums' => $dbi->inc()));

GZ_API::outPut(array('data' => $article));

/**
 * 今天
 * @param  string $field 字段名
 * @return string        sql
 */
function today_sql($field) {
	$t_today = date('Y-m-d', strtotime("today")); //strtotime参数是today时需用date()
	return "from_UNIXTIME(`{$field}`,'%Y-%m-%d')='{$t_today}'";
}

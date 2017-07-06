<?php
define('INIT_NO_USERS', true);
require EC_PATH . '/includes/init.php';
GZ_Api::authSession();

require EC_PATH . '/includes/lib_order.php';

/* 载入语言文件 */
require_once EC_PATH . '/languages/' . $_CFG['lang'] . '/user.php';
require_once EC_PATH . '/languages/' . $_CFG['lang'] . '/shopping_flow.php';
/*------------------------------------------------------ */
//-- INPUT
/*------------------------------------------------------ */
if (empty($tmp[0])) {
	GZ_Api::outPut(101);
}
/*------------------------------------------------------ */
//-- PROCESSOR
/*------------------------------------------------------ */

switch ($tmp[0]) {
case 'checkOrder':
	assign_template();
	assign_dynamic('flow');
	$position = assign_ur_here(0, $_LANG['shopping_flow']);
	$smarty->assign('page_title', $position['title']); // 页面标题
	$smarty->assign('ur_here', $position['ur_here']); // 当前位置

	$smarty->assign('categories', get_categories_tree()); // 分类树
	$smarty->assign('helps', get_shop_help()); // 网店帮助
	$smarty->assign('lang', $_LANG);
	$smarty->assign('show_marketprice', $_CFG['show_marketprice']);
	$smarty->assign('data_dir', DATA_DIR); // 数据目录
	/*------------------------------------------------------ */
	//-- 订单确认
	/*------------------------------------------------------ */

	/* 取得购物类型 */
	$flow_type = isset($_SESSION['flow_type']) ? intval($_SESSION['flow_type']) : CART_GENERAL_GOODS;
	$_SESSION['flow_order'] = array();
	/* 团购标志 */
	if ($flow_type == CART_GROUP_BUY_GOODS) {
		$smarty->assign('is_group_buy', 1);
	}
	/* 积分兑换商品 */
	elseif ($flow_type == CART_EXCHANGE_GOODS) {
		$smarty->assign('is_exchange_goods', 1);
	} else {
		//正常购物流程  清空其他购物流程情况
		$_SESSION['flow_order']['extension_code'] = '';
	}

	/* 检查购物车中是否有商品 */
	if ($dbi->where('session_id', SESS_ID)->where('parent_id', 0)->where('is_gift', 0)->where('rec_type', $flow_type)->getValue('cart', 'COUNT(*)') == 0) {
		GZ_Api::outPut(10002);
	}

	/*
		 * 检查用户是否已经登录
		 * 如果用户已经登录了则检查是否有默认的收货地址
		 * 如果没有登录则跳转到登录和注册页面
	*/
	if (empty($_SESSION['direct_shopping']) && $_SESSION['user_id'] == 0) {
		/* 用户没有登录且没有选定匿名购物，转向到登录页面 */
		GZ_Api::outPut(100);
		exit;
	}

	$consignee = get_consignee($_SESSION['user_id']);

	/* 检查收货人信息是否完整 */
	if (!check_consignee_info($consignee, $flow_type)) {
		/* 如果不完整则转向到收货人信息填写界面 */
		GZ_Api::outPut(10001);
		exit;
	}

	$_SESSION['flow_consignee'] = $consignee;
	$smarty->assign('consignee', $consignee);

	/* 对商品信息赋值 */
	$cart_goods = cart_goods($flow_type); // 取得商品列表，计算合计
	$smarty->assign('goods_list', $cart_goods);

	/* 对是否允许修改购物车赋值 */
	if ($flow_type != CART_GENERAL_GOODS || $_CFG['one_step_buy'] == '1') {
		$smarty->assign('allow_edit_cart', 0);
	} else {
		$smarty->assign('allow_edit_cart', 1);
	}

	/*
		     * 取得购物流程设置
		     */
	$smarty->assign('config', $_CFG);
	/*
		     * 取得订单信息
		     */
	$order = flow_order_info();
	$smarty->assign('order', $order);

	/* 计算折扣 */
	if ($flow_type != CART_EXCHANGE_GOODS && $flow_type != CART_GROUP_BUY_GOODS) {
		$discount = compute_discount();
		$smarty->assign('discount', $discount['discount']);
		$favour_name = empty($discount['name']) ? '' : join(',', $discount['name']);
		$smarty->assign('your_discount', sprintf($_LANG['your_discount'], $favour_name, price_format($discount['discount'])));
	}

	/*
		     * 计算订单的费用
		     */
	$total = order_fee($order, $cart_goods, $consignee);

	$smarty->assign('total', $total);
	$smarty->assign('shopping_money', sprintf($_LANG['shopping_money'], $total['formated_goods_price']));
	$smarty->assign('market_price_desc', sprintf($_LANG['than_market_price'], $total['formated_market_price'], $total['formated_saving'], $total['save_rate']));

	/* 取得配送列表 */
	$region = array($consignee['country'], $consignee['province'], $consignee['city'], $consignee['district']);
	$shipping_list = available_shipping_list($region);
	$cart_weight_price = cart_weight_price($flow_type);
	$insure_disabled = true;
	$cod_disabled = true;

	// 查看购物车中是否全为免运费商品，若是则把运费赋为零
	$shipping_count = $dbi->where('session_id', SESS_ID)->where('extension_code', 'package_buy', '!=')->where('is_shipping', 0)->getValue('cart', 'count(*)');

	$ck = array();
	foreach ($shipping_list AS $key => $val) {
		if (isset($ck[$val['shipping_id']])) {
			unset($shipping_list[$key]);
			continue;
		}
		$ck[$val['shipping_id']] = $val['shipping_id'];

		$shipping_cfg = unserialize_config($val['configure']);
		$shipping_fee = ($shipping_count == 0 AND $cart_weight_price['free_shipping'] == 1) ? 0 : shipping_fee($val['shipping_code'], unserialize($val['configure']),
			$cart_weight_price['weight'], $cart_weight_price['amount'], $cart_weight_price['number']);

		$shipping_list[$key]['format_shipping_fee'] = price_format($shipping_fee, false);
		$shipping_list[$key]['shipping_fee'] = $shipping_fee;
		$shipping_list[$key]['free_money'] = price_format($shipping_cfg['free_money'], false);
		$shipping_list[$key]['insure_formated'] = strpos($val['insure'], '%') === false ?
		price_format($val['insure'], false) : $val['insure'];

		/* 当前的配送方式是否支持保价 */
		if ($val['shipping_id'] == $order['shipping_id']) {
			$insure_disabled = ($val['insure'] == 0);
			$cod_disabled = ($val['support_cod'] == 0);
		}
	}
	$shipping_list = array_values($shipping_list);
	$smarty->assign('shipping_list', $shipping_list);
	$smarty->assign('insure_disabled', $insure_disabled);
	$smarty->assign('cod_disabled', $cod_disabled);

	/* 取得支付列表 */
	if ($order['shipping_id'] == 0) {
		$cod = true;
		$cod_fee = 0;
	} else {
		$shipping = shipping_info($order['shipping_id']);
		$cod = $shipping['support_cod'];

		if ($cod) {
			/* 如果是团购，且保证金大于0，不能使用货到付款 */
			if ($flow_type == CART_GROUP_BUY_GOODS) {
				$group_buy_id = $_SESSION['extension_id'];
				if ($group_buy_id <= 0) {
					GZ_Api::outPut(10006);
				}
				$group_buy = group_buy_info($group_buy_id);
				if (empty($group_buy)) {
					GZ_Api::outPut(101);
				}

				if ($group_buy['deposit'] > 0) {
					$cod = false;
					$cod_fee = 0;

					/* 赋值保证金 */
					$smarty->assign('gb_deposit', $group_buy['deposit']);
				}
			}

			if ($cod) {
				$shipping_area_info = shipping_area_info($order['shipping_id'], $region);
				$cod_fee = $shipping_area_info['pay_fee'];
			}
		} else {
			$cod_fee = 0;
		}
	}

	// 给货到付款的手续费加<span id>，以便改变配送的时候动态显示
	$is_wap = _POST('is_wap', 2);
	$is_weixin = strpos($_SERVER['HTTP_USER_AGENT'], 'MicroMessenger') !== false;
	$payment_list = available_payment_list(1, $cod_fee, false, $is_wap == 1 ? 1 : 2);
	if (isset($payment_list)) {
		foreach ($payment_list as $key => $payment) {
			if ($payment['is_cod'] == '1') {
				$payment_list[$key]['format_pay_fee'] = '<span id="ECS_CODFEE">' . $payment['format_pay_fee'] . '</span>';
			}
			/* 如果有易宝神州行支付 如果订单金额大于300 则不显示 */
			if ($payment['pay_code'] == 'yeepayszx' && $total['amount'] > 300) {
				unset($payment_list[$key]);
			}
			/* 如果金额大于20000 只保留余额支付 */
			if ($total['amount'] > 20000 && $payment['pay_code'] != 'balance') {
				unset($payment_list[$key]);
			}
			/* 如果有余额支付 */
			if ($payment['pay_code'] == 'balance') {
				/* 如果未登录，不显示 */
				if ($_SESSION['user_id'] == 0) {
					unset($payment_list[$key]);
				}
			}
			if ($is_wap == 1) {
				if ($is_weixin) {
					if ($payment['pay_code'] != 'wx_pay') {
						unset($payment_list[$key]);
					}
				} else {
					if ($payment['pay_code'] == 'wx_pay') {
						unset($payment_list[$key]);
					}
				}
			} else {
				if ($is_weixin) {
					if ($payment['pay_code'] == 'alipay_app') {
						unset($payment_list[$key]);
					}
				}
			}
		}
	}
	$smarty->assign('payment_list', $payment_list);

	/* 取得包装与贺卡 */
	if ($total['real_goods_count'] > 0) {
		/* 只有有实体商品,才要判断包装和贺卡 */
		if (!isset($_CFG['use_package']) || $_CFG['use_package'] == '1') {
			/* 如果使用包装，取得包装列表及用户选择的包装 */
			$smarty->assign('pack_list', pack_list());
		}

		/* 如果使用贺卡，取得贺卡列表及用户选择的贺卡 */
		if (!isset($_CFG['use_card']) || $_CFG['use_card'] == '1') {
			$smarty->assign('card_list', card_list());
		}
	}

	$user_info = user_info($_SESSION['user_id']);

	/* 如果使用余额，取得用户余额 */
	if ((!isset($_CFG['use_surplus']) || $_CFG['use_surplus'] == '1')
		&& $_SESSION['user_id'] > 0
		&& $user_info['user_money'] > 0) {
		// 能使用余额
		$smarty->assign('allow_use_surplus', 1);
		$smarty->assign('your_surplus', $user_info['user_money']);
	}

	$smarty->assign('your_integral', $user_info['pay_points']); // 用户积分
	/* 如果使用积分，取得用户可用积分及本订单最多可以使用的积分 */
	if ((!isset($_CFG['use_integral']) || $_CFG['use_integral'] == '1')
		&& $_SESSION['user_id'] > 0
		&& $user_info['pay_points'] > 0
		&& ($flow_type != CART_GROUP_BUY_GOODS && $flow_type != CART_EXCHANGE_GOODS)) {
		// 能使用积分
		// $smarty->assign('allow_use_integral', 1);
		$smarty->assign('order_max_integral', flow_available_points()); // 可用积分
	} else {
		$smarty->assign('order_max_integral', 0); // 可用积分
	}

	/* 如果使用红包，取得用户可以使用的红包及用户选择的红包 */
	if ((!isset($_CFG['use_bonus']) || $_CFG['use_bonus'] == '1')
		&& ($flow_type != CART_GROUP_BUY_GOODS && $flow_type != CART_EXCHANGE_GOODS)) {
		// 取得用户可用红包
		$user_bonus = user_bonus($_SESSION['user_id'], $total['goods_price']);
		if (!empty($user_bonus)) {
			foreach ($user_bonus AS $key => $val) {
				$user_bonus[$key]['bonus_money_formated'] = price_format($val['type_money'], false);
			}
			$smarty->assign('bonus_list', $user_bonus);
		}

		// 能使用红包
		$smarty->assign('allow_use_bonus', 1);
	}

	/* 如果使用缺货处理，取得缺货处理列表 */
	if (!isset($_CFG['use_how_oos']) || $_CFG['use_how_oos'] == '1') {
		if (is_array($GLOBALS['_LANG']['oos']) && !empty($GLOBALS['_LANG']['oos'])) {
			$smarty->assign('how_oos_list', $GLOBALS['_LANG']['oos']);
		}
	}

	/* 如果能开发票，取得发票内容列表 */
	if ((!isset($_CFG['can_invoice']) || $_CFG['can_invoice'] == '1')
		&& isset($_CFG['invoice_content'])
		&& trim($_CFG['invoice_content']) != '' && $flow_type != CART_EXCHANGE_GOODS) {
		$inv_content_list = explode("\n", str_replace("\r", '', $_CFG['invoice_content']));
		$smarty->assign('inv_content_list', $inv_content_list);

		$inv_type_list = array();
		foreach ($_CFG['invoice_type']['type'] as $key => $type) {
			if (!empty($type)) {
				$inv_type_list[$type] = $type . ' [' . floatval($_CFG['invoice_type']['rate'][$key]) . '%]';
			}
		}
		$smarty->assign('inv_type_list', $inv_type_list);
	}

	/* 保存 session */
	$_SESSION['flow_order'] = $order;

	$smarty->assign('currency_format', $_CFG['currency_format']);
	$smarty->assign('integral_scale', $_CFG['integral_scale']);
	assign_dynamic('shopping_flow');
	// print_r($smarty->_var);
	$out = array();
	// echo 'session<br/>';
	// $out['ecs_session'] = $smarty->_var['ecs_session'];
	$out['goods_list'] = $smarty->_var['goods_list']; //商品
	$out['consignee'] = $smarty->_var['consignee']; //收货地址
	$out['shipping_list'] = $smarty->_var['shipping_list']; //快递信息
	$out['payment_list'] = $smarty->_var['payment_list'];
	// $out['pack_list'] = $smarty->_var['pack_list']; //是否有包装
	// $out['card_list'] = $smarty->_var['card_list'];//贺卡 <!-- {if $card.card_img} 是否有图片 -->
	// $out['allow_use_surplus'] = $smarty->_var['allow_use_surplus'];//余额 是否使用余额
	if (!empty($smarty->_var['allow_use_integral'])) {
		$out['allow_use_integral'] = $smarty->_var['allow_use_integral']; //积分 是否使用积分
	}

	if (!empty($smarty->_var['allow_use_bonus'])) {
		$out['allow_use_bonus'] = $smarty->_var['allow_use_bonus']; //是否使用红包
	}

	if (!empty($smarty->_var['bonus_list'])) {
		$out['bonus'] = $smarty->_var['bonus_list']; //红包
	}

	$out['inv_content_list'] = $smarty->_var['inv_content_list']; //能否开发票
	$out['inv_type_list'] = $smarty->_var['inv_type_list']; //能否开发票
	// $out['how_oos_list'] = $smarty->_var['how_oos_list'];//是否使用缺货处理
	$out['your_integral'] = $user_info['pay_points']; //用户可用积分
	$out['order_max_integral'] = $smarty->_var['order_max_integral']; //订单最大可使用积分
	if (!empty($out['consignee'])) {
		$out['consignee']['id'] = $out['consignee']['address_id'];
		unset($out['consignee']['address_id']);
		unset($out['consignee']['user_id']);
		unset($out['consignee']['address_id']);
		$ids = array($out['consignee']["country"], $out['consignee']["province"], $out['consignee']["city"], $out['consignee']["district"]);
		$ids = array_filter($ids);

		$data = $dbi->where('region_id', $ids, 'IN')->get('region');
		$a_out = array();
		foreach ($data as $key => $val) {
			$a_out[$val['region_id']] = $val['region_name'];
		}

		$out['consignee']["country_name"] = isset($a_out[$out['consignee']["country"]]) ? $a_out[$out['consignee']["country"]] : '';
		$out['consignee']["province_name"] = isset($a_out[$out['consignee']["province"]]) ? $a_out[$out['consignee']["province"]] : '';
		$out['consignee']["city_name"] = isset($a_out[$out['consignee']["city"]]) ? $a_out[$out['consignee']["city"]] : '';
		$out['consignee']["district_name"] = isset($a_out[$out['consignee']["district"]]) ? $a_out[$out['consignee']["district"]] : '';

	}
	if (!empty($out['inv_content_list'])) {
		$temp = array();
		foreach ($out['inv_content_list'] as $key => $value) {
			$temp[] = array('id' => $key, 'value' => $value);
		}
		$out['inv_content_list'] = $temp;
	}
	if (!empty($out['inv_type_list'])) {
		$temp = array();
		$i = 0;
		foreach ($out['inv_type_list'] as $key => $value) {
			$temp[] = array('id' => $i, 'value' => $value);
			$i++;
		}
		$out['inv_type_list'] = $temp;
	}

	//去掉系统使用的字段
	if (!empty($out['shipping_list'])) {
		foreach ($out['shipping_list'] as $key => $value) {
			unset($out['shipping_list'][$key]['configure']);
			unset($out['shipping_list'][$key]['shipping_desc']);
		}
	}

	if (!empty($out['payment_list'])) {

		foreach ($out['payment_list'] as $key => $value) {
			unset($out['payment_list'][$key]['pay_config']);
			unset($out['payment_list'][$key]['pay_desc']);
			$out['payment_list'][$key]['pay_name'] = strip_tags($value['pay_name']);
			// cod 货到付款，alipay支付宝，bank银行转账
			if (in_array($value['pay_code'], array('post'))) {
				unset($out['payment_list'][$key]);
			}
			// $out['shipping_list'][$key]['configure'] = unserialize($value['configure']);
		}
		$out['payment_list'] = array_values($out['payment_list']);
	}

	if (!empty($out['goods_list'])) {
		foreach ($out['goods_list'] as $key => $value) {
			if (!empty($value['goods_attr'])) {
				$goods_attr = explode("\n", $value['goods_attr']);
				$goods_attr = array_filter($goods_attr);
				$out['goods_list'][$key]['goods_attr'] = array();
				foreach ($goods_attr as $v) {
					$a = explode(':', $v);
					if (!empty($a[0]) && !empty($a[1])) {
						$out['goods_list'][$key]['goods_attr'][] = array('name' => $a[0], 'value' => $a[1]);
					}
				}
			}
		}
	}
	$out['your_surplus'] = isset($smarty->_var['your_surplus']) ? $smarty->_var['your_surplus'] : 0;
	if ($total['amount'] > 20000) {
		// 超过2万选中余额付款
		$_SESSION['flow_order']['pay_id'] = 4;
	}
	$out['pay_id'] = $_SESSION['flow_order']['pay_id'];
	$out['shipping_id'] = $_SESSION['flow_order']['shipping_id'];
	$out['amount'] = $total['amount'];
	$out['goods_price'] = $total['goods_price'];
	$out['shipping_fee'] = $total['shipping_fee'];
	$out['exchange_integral'] = isset($total['exchange_integral']) ? $total['exchange_integral'] : 0;
	// print_r($out);exit;
	GZ_API::outPut($out);
	break;
case 'done':
	if (empty($_SESSION['user_id'])) {
		GZ_API::outPut(100);
	}
	// bonus	0 //红包
	// how_oos	0 //缺货处理
	// integral	0 //积分
	// payment	3 //支付方式
	// postscript	//订单留言
	// shipping	3   //配送方式
	// surplus	0  //余额
	//inv_type	4 //发票类型
	//inv_payee 发票抬头
	//inv_content 发票内容

	include_once EC_PATH . '/includes/lib_clips.php';
	include_once EC_PATH . '/includes/lib_payment.php';

	/* 取得购物类型 */
	$flow_type = isset($_SESSION['flow_type']) ? intval($_SESSION['flow_type']) : CART_GENERAL_GOODS;
	$cart_extend = _POST('extend', array());
	/* 检查购物车中是否有商品 */
	if ($dbi->where('session_id', SESS_ID)->where('parent_id', 0)->where('is_gift', 0)->where('rec_type', $flow_type)->getValue('cart', 'COUNT(*)') == 0) {
		GZ_Api::outPut(10002);
	}

	/* 检查商品库存 */
	/* 如果使用库存，且下订单时减库存，则减少库存 */
	if ($_CFG['use_storage'] == '1' && $_CFG['stock_dec_time'] == SDT_PLACE) {
		$cart_goods_stock = get_cart_goods();
		$_cart_goods_stock = array();
		foreach ($cart_goods_stock['goods_list'] as $value) {
			$_cart_goods_stock[$value['rec_id']] = $value['goods_number'];
		}
		flow_cart_stock($_cart_goods_stock);
		unset($cart_goods_stock, $_cart_goods_stock);
	}

	/*
		     * 检查用户是否已经登录
		     * 如果用户已经登录了则检查是否有默认的收货地址
		     * 如果没有登录则跳转到登录和注册页面
	*/
	if (empty($_SESSION['direct_shopping']) && $_SESSION['user_id'] == 0) {
		/* 用户没有登录且没有选定匿名购物，转向到登录页面 */
		GZ_Api::outPut(100);
		exit;
	}

	$consignee = get_consignee($_SESSION['user_id']);
	//print_r($_SESSION);exit;
	/* 检查收货人信息是否完整 */
	if (!check_consignee_info($consignee, $flow_type)) {
		/* 如果不完整则转向到收货人信息填写界面 */
		GZ_Api::outPut(10001);
		exit;
	}
	/* 充值类特殊判断 */
	if (!empty($cart_extend)) {
		/* 充流量、话费必须有电话号码 */
		if ($cart_extend['code'] == 'call' || $cart_extend['code'] == 'flow') {
			if (empty($consignee['tel'])) {
				GZ_Api::outPut(10001);
			}
		}
	}

	$_POST['how_oos'] = isset($_POST['how_oos']) ? intval($_POST['how_oos']) : 0;
	$_POST['card_message'] = isset($_POST['card_message']) ? htmlspecialchars($_POST['card_message']) : '';
	$_POST['inv_type'] = !empty($_POST['inv_type']) ? htmlspecialchars($_POST['inv_type']) : '';
	$_POST['inv_payee'] = isset($_POST['inv_payee']) ? htmlspecialchars($_POST['inv_payee']) : '';
	$_POST['inv_content'] = isset($_POST['inv_content']) ? htmlspecialchars($_POST['inv_content']) : '';
	$_POST['postscript'] = isset($_POST['postscript']) ? htmlspecialchars($_POST['postscript']) : '';

	$order = array(
		'shipping_id' => intval($_POST['shipping_id']),
		//'shipping_id'     => 5,
		'pay_id' => intval($_POST['pay_id']),
		//'pay_id'          => 4,
		'pack_id' => isset($_POST['pack']) ? intval($_POST['pack']) : 0,
		'card_id' => isset($_POST['card']) ? intval($_POST['card']) : 0,
		'card_message' => trim($_POST['card_message']),
		'surplus' => isset($_POST['surplus']) ? floatval($_POST['surplus']) : 0.00,
		'integral' => isset($_POST['integral']) ? intval($_POST['integral']) : 0,
		'bonus_id' => isset($_POST['bonus_id']) ? intval($_POST['bonus_id']) : 0,
		'need_inv' => empty($_POST['need_inv']) ? 0 : 1,
		'inv_type' => $_POST['inv_type'],
		'inv_payee' => trim($_POST['inv_payee']),
		'inv_content' => $_POST['inv_content'],
		'postscript' => trim($_POST['postscript']),
		'how_oos' => isset($_LANG['oos'][$_POST['how_oos']]) ? addslashes($_LANG['oos'][$_POST['how_oos']]) : '',
		'need_insure' => isset($_POST['need_insure']) ? intval($_POST['need_insure']) : 0,
		'user_id' => $_SESSION['user_id'],
		'add_time' => gmtime(),
		'order_status' => OS_UNCONFIRMED,
		'shipping_status' => SS_UNSHIPPED,
		'pay_status' => PS_UNPAYED,
		'agency_id' => get_agency_by_regions(array($consignee['country'], $consignee['province'], $consignee['city'], $consignee['district'])),
	);

	/* 扩展信息 */
	if (isset($_SESSION['flow_type']) && intval($_SESSION['flow_type']) != CART_GENERAL_GOODS) {
		$order['extension_code'] = $_SESSION['extension_code'];
		$order['extension_id'] = $_SESSION['extension_id'];
	} else {
		$order['extension_code'] = '';
		$order['extension_id'] = 0;
	}

	/* 检查积分余额是否合法 */
	$user_id = $_SESSION['user_id'];
	if ($user_id > 0) {
		$user_info = user_info($user_id);

		$order['surplus'] = min($order['surplus'], $user_info['user_money'] + $user_info['credit_line']);
		if ($order['surplus'] < 0) {
			$order['surplus'] = 0;
		}

		// 查询用户有多少积分
		$flow_points = flow_available_points(); // 该订单允许使用的积分
		$user_points = $user_info['pay_points']; // 用户的积分总数

		$order['integral'] = min($order['integral'], $user_points, $flow_points);
		if ($order['integral'] < 0) {
			$order['integral'] = 0;
		}
	} else {
		GZ_API::outPut(100);
		//$order['surplus']  = 0;
		//$order['integral'] = 0;
	}

	/* 检查红包是否存在 */
	if ($order['bonus_id'] > 0) {
		$bonus = bonus_info($order['bonus_id']);

		if (empty($bonus) || $bonus['user_id'] != $user_id || $bonus['order_id'] > 0 || $bonus['min_goods_amount'] > cart_amount(true, $flow_type)) {
			$order['bonus_id'] = 0;
		}
	} elseif (isset($_POST['bonus_sn'])) {
		$bonus_sn = trim($_POST['bonus_sn']);
		$bonus = bonus_info(0, $bonus_sn);
		$now = gmtime();
		if (empty($bonus) || $bonus['user_id'] > 0 || $bonus['order_id'] > 0 || $bonus['min_goods_amount'] > cart_amount(true, $flow_type) || $now > $bonus['use_end_date']) {
		} else {
			if ($user_id > 0) {
				$dbi->where('bonus_id', $bonus['bonus_id'])->update('user_bonus', array('user_id' => $user_id), 1);
			}
			$order['bonus_id'] = $bonus['bonus_id'];
			$order['bonus_sn'] = $bonus_sn;
		}
	}

	/* 订单中的商品 */
	$cart_goods = cart_goods($flow_type);

	if (empty($cart_goods)) {
		GZ_Api::outPut(10002);
	}

	/* 检查商品总额是否达到最低限购金额 */
	if ($flow_type == CART_GENERAL_GOODS && cart_amount(true, CART_GENERAL_GOODS) < $_CFG['min_goods_amount']) {
		GZ_Api::outPut(10003);

	}

	/* 收货人信息 */
	foreach ($consignee as $key => $value) {
		$order[$key] = addslashes($value);
	}

	/* 判断是不是实体商品 */
	foreach ($cart_goods AS $val) {
		/* 统计实体商品的个数 */
		if ($val['is_real']) {
			$is_real_good = 1;
		}
	}
	if (isset($is_real_good)) {
		if (!$dbi->where('shipping_id', $order['shipping_id'])->where('enabled', 1)->getValue('shipping', 'shipping_id')) {
			GZ_Api::outPut(10001);
		}
	}
	/* 订单中的总额 */
	$total = order_fee($order, $cart_goods, $consignee);
	$order['bonus'] = $total['bonus'];
	$order['goods_amount'] = $total['goods_price'];
	$order['discount'] = $total['discount'] ? $total['discount'] : 0;
	$order['surplus'] = $total['surplus'];
	$order['tax'] = $total['tax'];

	// 购物车中的商品能享受红包支付的总额
	$discount_amout = compute_discount_amount();
	// 红包和积分最多能支付的金额为商品总额
	$temp_amout = $order['goods_amount'] - $discount_amout;
	if ($temp_amout <= 0) {
		$order['bonus_id'] = 0;
	}

	/* 配送方式 */
	if ($order['shipping_id'] > 0) {
		$shipping = shipping_info($order['shipping_id']);
		$order['shipping_name'] = addslashes($shipping['shipping_name']);
	}
	$order['shipping_fee'] = $total['shipping_fee'];
	$order['insure_fee'] = $total['shipping_insure'];

	/* 支付方式 */
	if ($order['pay_id'] > 0) {
		$payment = payment_info($order['pay_id']);
		$order['pay_name'] = addslashes($payment['pay_name']);
	}
	$order['pay_fee'] = $total['pay_fee'];
	$order['cod_fee'] = $total['cod_fee'];

	/* 商品包装 */
	if ($order['pack_id'] > 0) {
		$pack = pack_info($order['pack_id']);
		$order['pack_name'] = addslashes($pack['pack_name']);
	}
	$order['pack_fee'] = $total['pack_fee'];

	/* 祝福贺卡 */
	if ($order['card_id'] > 0) {
		$card = card_info($order['card_id']);
		$order['card_name'] = addslashes($card['card_name']);
	}
	$order['card_fee'] = $total['card_fee'];

	$order['order_amount'] = number_format($total['amount'], 2, '.', '');

	/* 如果全部使用余额支付，检查余额是否足够 */
	if ($payment['pay_code'] == 'balance' && $order['order_amount'] > 0) {
		if ($order['surplus'] > 0) //余额支付里如果输入了一个金额
		{
			$order['order_amount'] = $order['order_amount'] + $order['surplus'];
			$order['surplus'] = 0;
		}
		if ($order['order_amount'] > ($user_info['user_money'] + $user_info['credit_line'])) {
			GZ_Api::outPut(10003);
		} else {
			$order['surplus'] = $order['order_amount'];
			$order['order_amount'] = 0;
		}
	}

	/* 如果订单金额为0（使用余额或积分或红包支付），修改订单状态为已确认、已付款 */
	if ($order['order_amount'] <= 0) {
		$order['order_status'] = OS_CONFIRMED;
		$order['confirm_time'] = gmtime();
		$order['pay_status'] = PS_PAYED;
		$order['pay_time'] = gmtime();
		$order['order_amount'] = 0;
	}

	$order['integral_money'] = $total['integral_money'];
	$order['integral'] = $total['integral'];

	if ($order['extension_code'] == 'exchange_goods') {
		$order['integral_money'] = 0;
		$order['integral'] = $total['exchange_integral'];
	}

	$order['from_ad'] = !empty($_SESSION['from_ad']) ? $_SESSION['from_ad'] : '0';
	$order['referer'] = !empty($_SESSION['referer']) ? addslashes($_SESSION['referer']) : '';

	/* 记录扩展信息 */
	if ($flow_type != CART_GENERAL_GOODS) {
		$order['extension_code'] = $_SESSION['extension_code'];
		$order['extension_id'] = $_SESSION['extension_id'];
	}

	$affiliate = unserialize($_CFG['affiliate']);
	if (isset($affiliate['on']) && $affiliate['on'] == 1 && $affiliate['config']['separate_by'] == 1) {
		//推荐订单分成
		$parent_id = get_affiliate();
		if ($user_id == $parent_id) {
			$parent_id = 0;
		}
	} elseif (isset($affiliate['on']) && $affiliate['on'] == 1 && $affiliate['config']['separate_by'] == 0) {
		//推荐注册分成
		$parent_id = 0;
	} else {
		//分成功能关闭
		$parent_id = 0;
	}
	$order['parent_id'] = $parent_id;

	/* 插入订单表 */
	$error_no = 0;
	try {
		/*删除未知字段名 开始*/
		if (isset($order['address_id'])) {
			unset($order['address_id']);
		}
		if (isset($order['default'])) {
			unset($order['default']);
		}
		if (isset($order['need_insure'])) {
			unset($order['need_insure']);
		}
		if (isset($order['need_inv'])) {
			unset($order['need_inv']);
		}
		if (isset($order['address_name'])) {
			unset($order['address_name']);
		}
		if (isset($order['cod_fee'])) {
			unset($order['cod_fee']);
		}
		/*结束*/
		$order['order_sn'] = get_order_sn('o'); //获取新订单号
		$new_order_id = $dbi->insert('order_info', $order);

	} catch (Exception $e) {
		log::write($e->getMessage(), 'db');
		GZ_Api::outPut(-1, null, '系统错误：' . $e->getCode());
	}
	
	$order['order_id'] = $new_order_id;

	$dbi->startTransaction();
	try {
		/* 插入订单商品 */
		$sql = "INSERT INTO " . $ecs->table('order_goods') . "( " .
		"order_id, goods_id, goods_name, goods_sn, product_id, goods_number, market_price, " .
		"goods_price, goods_attr, is_real, extension_code, parent_id, is_gift, goods_attr_id) " .
		" SELECT ?, goods_id, goods_name, goods_sn, product_id, goods_number, market_price, " .
		"goods_price, goods_attr, is_real, extension_code, parent_id, is_gift, goods_attr_id" .
		" FROM " . $ecs->table('cart') .
			" WHERE session_id = ? AND rec_type = ?";
		$dbi->rawQuery($sql, array($new_order_id, SESS_ID, $flow_type));
		/* 修改拍卖活动状态 */
		if ($order['extension_code'] == 'auction') {
			$dbi->where('act_id', $order['extension_id'])->update('goods_activity', array('is_finished' => 2));
		}

		/* 处理余额、积分、红包 */
		if ($order['user_id'] > 0 && $order['surplus'] > 0) {
			log_account_change($order['user_id'], $order['surplus'] * (-1), 0, 0, 0, sprintf($_LANG['pay_order'], $order['order_sn']));
		}
		if ($order['user_id'] > 0 && $order['integral'] > 0) {
			log_account_change($order['user_id'], 0, 0, 0, $order['integral'] * (-1), sprintf($_LANG['pay_order'], $order['order_sn']));
		}

		if ($order['bonus_id'] > 0 && $temp_amout > 0) {
			use_bonus($order['bonus_id'], $new_order_id);
		}

		/* 如果使用库存，且下订单时减库存，则减少库存 */
		if ($_CFG['use_storage'] == '1' && $_CFG['stock_dec_time'] == SDT_PLACE) {
			change_order_goods_storage($order['order_id'], true, SDT_PLACE);
		}

		/* 如果订单有回调处理 */
		if (!empty($cart_extend)) {
			$dbi->where('order_id', $new_order_id)->update('order_info', array('extension_code' => $cart_extend['code'], 'extension_id' => $cart_extend['id']));
		}
		$dbi->commit();
	} catch (Exception $e) {
		$dbi->rollback();
		log::write($e->getMessage(), 'db');
		GZ_Api::outPut(-1, null, '系统错误：' . $e->getCode());
	}

	/* 给商家发邮件 */
	/* 增加是否给客服发送邮件选项 */
	if ($_CFG['send_service_email'] && $_CFG['service_email'] != '') {
		$tpl = get_mail_template('remind_of_new_order');
		$smarty->assign('order', $order);
		$smarty->assign('goods_list', $cart_goods);
		$smarty->assign('shop_name', $_CFG['shop_name']);
		$smarty->assign('send_date', date($_CFG['time_format']));
		$content = $smarty->fetch('str:' . $tpl['template_content']);
		send_mail($_CFG['shop_name'], $_CFG['service_email'], $tpl['template_subject'], $content, $tpl['is_html']);
	}

	/* 如果需要，发短信 */
	if ($_CFG['sms_order_placed'] == '1' && $_CFG['sms_shop_mobile'] != '') {
		include_once 'includes/cls_sms.php';
		$sms = new sms();
		$msg = $order['pay_status'] == PS_UNPAYED ?
		$_LANG['order_placed_sms'] : $_LANG['order_placed_sms'] . '[' . $_LANG['sms_paid'] . ']';
		$sms->send($_CFG['sms_shop_mobile'], sprintf($msg, $order['consignee'], $order['tel']), '', 13, 1);
	}

	/* 如果订单金额为0 处理虚拟卡 */
	if ($order['order_amount'] <= 0) {
		$res = $dbi->where('is_real', 0)->where('extension_code', 'virtual_card')->where('session_id', SESS_ID)->where('rec_type', $flow_type)->get('cart', null, 'goods_id, goods_name, goods_number num');
		$virtual_goods = array();
		foreach ($res AS $row) {
			$virtual_goods['virtual_card'][] = array('goods_id' => $row['goods_id'], 'goods_name' => $row['goods_name'], 'num' => $row['num']);
		}

		if ($virtual_goods AND $flow_type != CART_GROUP_BUY_GOODS) {
			/* 虚拟卡发货 */
			if (virtual_goods_ship($virtual_goods, $msg, $order['order_sn'], true)) {
				/* 如果没有实体商品，修改发货状态，送积分和红包 */
				if ($dbi->where('order_id', $order['order_id'])->where('is_real', 1)->getValue('order_goods', 'COUNT(*)') <= 0) {
					/* 修改订单状态 */
					update_order($order['order_id'], array('shipping_status' => SS_SHIPPED, 'shipping_time' => gmtime()));

					/* 如果订单用户不为空，计算积分，并发给用户；发红包 */
					if ($order['user_id'] > 0) {
						/* 取得用户信息 */
						$user = user_info($order['user_id']);

						/* 计算并发放积分 */
						$integral = integral_to_give($order);
						log_account_change($order['user_id'], 0, 0, intval($integral['rank_points']), intval($integral['custom_points']), sprintf($_LANG['order_gift_integral'], $order['order_sn']));

						/* 发放红包 */
						send_order_bonus($order['order_id']);
					}
				}
			}
		}

	}

	/* 清空购物车 */
	clear_cart($flow_type);
	/* 清除缓存，否则买了商品，但是前台页面读取缓存，商品数量不减少 */
	clear_all_files();

	/* 插入支付日志 */
	$order['log_id'] = insert_pay_log($new_order_id, $order['order_amount'], PAY_ORDER);

	/* 取得支付信息，生成支付代码 */
	if ($order['order_amount'] > 0) {
		$payment = payment_info($order['pay_id']);

		include_once 'includes/modules/payment/' . $payment['pay_code'] . '.php';

		$pay_obj = new $payment['pay_code'];

		$order['get_url'] = 1;
		$order['pay_online'] = $pay_online = $pay_obj->get_code($order, unserialize_config($payment['pay_config']));

		$order['pay_desc'] = $payment['pay_desc'];

		$smarty->assign('pay_online', $pay_online);

	}
	if (!empty($order['shipping_name'])) {
		$order['shipping_name'] = trim(stripcslashes($order['shipping_name']));
	}

	/* 订单信息 */
	$smarty->assign('order', $order);
	$smarty->assign('total', $total);
	$smarty->assign('goods_list', $cart_goods);
	$smarty->assign('order_submit_back', sprintf($_LANG['order_submit_back'], $_LANG['back_home'], $_LANG['goto_user_center'])); // 返回提示

	user_uc_call('add_feed', array($order['order_id'], BUY_GOODS)); //推送feed到uc
	unset($_SESSION['flow_consignee']); // 清除session中保存的收货人信息
	unset($_SESSION['flow_order']);
	unset($_SESSION['direct_shopping']);
	unset($_SESSION['flow_type']);

	/* 积分兑换、余额支付等立即计算提成 */
	if ($order['pay_status'] == PS_PAYED) {
		/* 话费、流量、加油卡充值 */
		if ($cart_extend['code'] == 'call' || $cart_extend['code'] == 'flow' || $cart_extend['code'] == 'sinopec') {
			async_exec('/douli_submit.php?order_sn=' . $order['order_sn']);
		}

		setCommission($new_order_id);
	}
	$subject = $cart_goods[0]['goods_name'] . '等' . count($cart_goods) . '种商品';
	// print_r($smarty->_var['order']['order_sn']);
	$out = array('order_sn' => $smarty->_var['order']['order_sn'], 'order_id' => $order['order_id'], 'order_info' => array(
		'pay_code' => $payment['pay_code'],
		'pay_online' => $order['pay_online'],
		'order_amount' => $order['order_amount'],
		'order_id' => $order['order_id'],
		'subject' => $subject,
		'desc' => $subject,
		'order_sn' => $order['order_sn'],
	));

	GZ_Api::outPut($out);
	break;
case "changeBonus":
	$bonus_id = _POST('bonus_id', 0);
	/* 取得购物类型 */
	$flow_type = isset($_SESSION['flow_type']) ? intval($_SESSION['flow_type']) : CART_GENERAL_GOODS;
	/* 获得收货人信息 */
	$consignee = get_consignee($_SESSION['user_id']);
	/* 对商品信息赋值 */
	$cart_goods = cart_goods($flow_type); // 取得商品列表，计算合计
	if (empty($cart_goods) || !check_consignee_info($consignee, $flow_type)) {
		GZ_API::outPut(10016);
	} else {
		/* 取得订单信息 */
		$order = flow_order_info();
		$bonus = bonus_info($bonus_id);
		if ((!empty($bonus) && $bonus['user_id'] == $_SESSION['user_id']) || $_GET['bonus'] == 0) {
			$order['bonus_id'] = $bonus_id;
		} else {
			$order['bonus_id'] = 0;
			GZ_API::outPut(10017);
		}
		/* 计算订单的费用 */
		$total = order_fee($order, $cart_goods, $consignee);
		GZ_API::outPut(array(
			'goods_price' => $total['goods_price'],
			'shipping_fee' => $total['shipping_fee'],
			'amount' => $total['amount'],
		));
	}
	break;
case "select_shipping":
	/* 取得购物类型 */
	$flow_type = isset($_SESSION['flow_type']) ? intval($_SESSION['flow_type']) : CART_GENERAL_GOODS;
	/* 获得收货人信息 */
	$consignee = get_consignee($_SESSION['user_id']);
	/* 对商品信息赋值 */
	$cart_goods = cart_goods($flow_type); // 取得商品列表，计算合计
	if (empty($cart_goods)) {
		GZ_API::outPut(10016);
	} else {
		/* 取得订单信息 */
		$order = flow_order_info();
		$order['shipping_id'] = intval($_REQUEST['shipping']);
		$_SESSION['flow_order'] = $order;
		/* 计算订单的费用 */
		$total = order_fee($order, $cart_goods, $consignee);
		GZ_API::outPut(array(
			'goods_price' => $total['goods_price'],
			'shipping_fee' => $total['shipping_fee'],
			'amount' => $total['amount'],
		));
	}
	break;
case "select_payment":
	/* 取得购物类型 */
	$flow_type = isset($_SESSION['flow_type']) ? intval($_SESSION['flow_type']) : CART_GENERAL_GOODS;
	/* 获得收货人信息 */
	$consignee = get_consignee($_SESSION['user_id']);
	/* 对商品信息赋值 */
	$cart_goods = cart_goods($flow_type); // 取得商品列表，计算合计
	if (empty($cart_goods)) {
		GZ_API::outPut(10016);
	} else {
		/* 取得订单信息 */
		$order = flow_order_info();
		$order['pay_id'] = intval($_REQUEST['payment']);
		$_SESSION['flow_order'] = $order;
		/* 计算订单的费用 */
		$total = order_fee($order, $cart_goods, $consignee);
		GZ_API::outPut(array(
			'goods_price' => $total['goods_price'],
			'shipping_fee' => $total['shipping_fee'],
			'amount' => $total['amount'],
		));
	}
	break;
default:
	# code...
	break;
}

/*------------------------------------------------------ */
//-- PRIVATE FUNCTION
/*------------------------------------------------------ */

/**
 * 获得用户的可用积分
 *
 * @access  private
 * @return  integral
 */
function flow_available_points() {
	$sql = "SELECT SUM(g.integral * c.goods_number) " .
	"FROM " . $GLOBALS['ecs']->table('cart') . " AS c, " . $GLOBALS['ecs']->table('goods') . " AS g " .
		"WHERE c.session_id = ? AND c.goods_id = g.goods_id AND c.is_gift = 0 AND g.integral > 0 " .
		"AND c.rec_type = ? LIMIT 1";

	$val = intval($GLOBALS['dbi']->rawQueryValue($sql, array(SESS_ID, CART_GENERAL_GOODS)));

	return integral_of_value($val);
}

/**
 * 更新购物车中的商品数量
 *
 * @access  public
 * @param   array   $arr
 * @return  void
 */
function flow_update_cart($arr) {
	/* 处理 */
	foreach ($arr AS $key => $val) {
		$val = intval(make_semiangle($val));
		if ($val <= 0 || !is_numeric($key)) {
			continue;
		}

		//查询：
		$goods = $GLOBALS['dbi']->where('rec_id', $key)->where('session_id', SESS_ID)->getOne('cart', 'goods_id,goods_attr_id,product_id,extension_code');

		$sql = "SELECT g.`goods_name`, g.`goods_number` " .
		"FROM " . $GLOBALS['ecs']->table('goods') . " AS g, " .
		$GLOBALS['ecs']->table('cart') . " AS c " .
			"WHERE g.goods_id = c.goods_id AND c.rec_id = ?  LIMIT 1";
		$row = $GLOBALS['dbi']->rawQueryOne($sql, array($key));

		//查询：系统启用了库存，检查输入的商品数量是否有效
		if (intval($GLOBALS['_CFG']['use_storage']) > 0 && $goods['extension_code'] != 'package_buy') {
			if ($row['goods_number'] < $val) {
				GZ_Api::outPut(10008);
			}
			/* 是货品 */
			$goods['product_id'] = trim($goods['product_id']);
			if (!empty($goods['product_id'])) {
				$product_number = $GLOBALS['dbi']->where('goods_id', $goods['goods_id'])->where('product_id', $goods['product_id'])->getValue('products', 'product_number');
				if ($product_number < $val) {
					GZ_Api::outPut(10008);
				}
			}
		} elseif (intval($GLOBALS['_CFG']['use_storage']) > 0 && $goods['extension_code'] == 'package_buy') {
			if (judge_package_stock($goods['goods_id'], $val)) {
				GZ_Api::outPut(10005);
			}
		}

		/* 查询：检查该项是否为基本件 以及是否存在配件 */
		/* 此处配件是指添加商品时附加的并且是设置了优惠价格的配件 此类配件都有parent_id goods_number为1 */
		$sql = "SELECT b.`goods_number`, b.`rec_id`
                FROM " . $GLOBALS['ecs']->table('cart') . " a, " . $GLOBALS['ecs']->table('cart') . " b
                WHERE a.rec_id = ?
                AND a.session_id = ?
                AND a.extension_code <> 'package_buy'
                AND b.parent_id = a.goods_id
                AND b.session_id = ?";

		$offers_accessories_res = $GLOBALS['dbi']->rawQuery($sql, array($key, SESS_ID, SESS_ID));

		//订货数量大于0
		if ($val > 0) {
			/* 判断是否为超出数量的优惠价格的配件 删除*/
			$row_num = 1;
			foreach ($offers_accessories_res as $offers_accessories_row) {
				if ($row_num > $val) {
					$GLOBALS['dbi']->where('session_id', SESS_ID)->where('rec_id', $offers_accessories_row['rec_id'])->delete('cart', 1);
				}

				$row_num++;
			}

			/* 处理超值礼包 */
			if ($goods['extension_code'] == 'package_buy') {
				//更新购物车中的商品数量
				$GLOBALS['dbi']->where('rec_id', $key)->where('session_id', SESS_ID)->update('cart', array('goods_number' => $val));
			}
			/* 处理普通商品或非优惠的配件 */
			else {
				$attr_id = empty($goods['goods_attr_id']) ? array() : explode(',', $goods['goods_attr_id']);
				$goods_price = get_final_price($goods['goods_id'], $val, true, $attr_id);

				//更新购物车中的商品数量
				$GLOBALS['dbi']->where('rec_id', $key)->where('session_id', SESS_ID)->update('cart', array('goods_number' => $val, 'goods_price' => $goods_price));
			}
		}
		//订货数量等于0
		else {
			/* 如果是基本件并且有优惠价格的配件则删除优惠价格的配件 */
			foreach ($offers_accessories_res as $offers_accessories_row) {
				$GLOBALS['dbi']->where('session_id', SESS_ID)->where('rec_id', $offers_accessories_row['rec_id'])->delete('cart', 1);
			}

			$GLOBALS['dbi']->where('rec_id', $key)->where('session_id', SESS_ID)->delete('cart');
		}
	}

	/* 删除所有赠品 */
	$GLOBALS['dbi']->where('session_id', SESS_ID)->where('is_gift', 0, '<>')->delete('cart');
}

/**
 * 检查订单中商品库存
 *
 * @access  public
 * @param   array   $arr
 *
 * @return  void
 */
function flow_cart_stock($arr) {
	foreach ($arr AS $key => $val) {
		$val = intval(make_semiangle($val));
		if ($val <= 0 || !is_numeric($key)) {
			continue;
		}

		$goods = $GLOBALS['dbi']->where('rec_id', $key)->where('session_id', SESS_ID)->getOne('cart', 'goods_id,goods_attr_id,extension_code');

		$sql = "SELECT g.`goods_name`, g.`goods_number`, c.`product_id` " .
		"FROM " . $GLOBALS['ecs']->table('goods') . " AS g, " .
		$GLOBALS['ecs']->table('cart') . " AS c " .
			"WHERE g.goods_id = c.goods_id AND c.rec_id = ?  LIMIT 1";
		$row = $GLOBALS['dbi']->rawQueryOne($sql, array($key));

		//系统启用了库存，检查输入的商品数量是否有效
		if (intval($GLOBALS['_CFG']['use_storage']) > 0 && $goods['extension_code'] != 'package_buy') {
			if ($row['goods_number'] < $val) {
				GZ_Api::outPut(10008);
				exit;
			}

			/* 是货品 */
			$row['product_id'] = trim($row['product_id']);
			if (!empty($row['product_id'])) {
				$product_number = $GLOBALS['dbi']->where('goods_id', $goods['goods_id'])->where('product_id', $row['product_id'])->getValue('products', 'product_number');
				if ($product_number < $val) {
					GZ_Api::outPut(10005);
					exit;
				}
			}
		} elseif (intval($GLOBALS['_CFG']['use_storage']) > 0 && $goods['extension_code'] == 'package_buy') {
			if (judge_package_stock($goods['goods_id'], $val)) {
				GZ_Api::outPut(10005);
				exit;
			}
		}
	}

}

/**
 * 删除购物车中的商品
 *
 * @access  public
 * @param   integer $id
 * @return  void
 */
function flow_drop_cart_goods($id) {
	/* 取得商品id */
	$row = $GLOBALS['dbi']->where('rec_id', $id)->getOne('cart');
	if ($row) {
		//如果是超值礼包
		if ($row['extension_code'] == 'package_buy') {
			$GLOBALS['dbi']->where('session_id', SESS_ID)->where('rec_id', $id)->delete('cart', 1);
		}

		//如果是普通商品，同时删除所有赠品及其配件
		elseif ($row['parent_id'] == 0 && $row['is_gift'] == 0) {
			/* 检查购物车中该普通商品的不可单独销售的配件并删除 */
			$sql = "SELECT c.`rec_id`
                    FROM " . $GLOBALS['ecs']->table('cart') . " AS c, " . $GLOBALS['ecs']->table('group_goods') . " AS gg, " . $GLOBALS['ecs']->table('goods') . " AS g
                    WHERE gg.parent_id = ?
                    AND c.goods_id = gg.goods_id
                    AND c.parent_id = ?
                    AND c.extension_code <> 'package_buy'
                    AND gg.goods_id = g.goods_id
                    AND g.is_alone_sale = 0";
			$res = $GLOBALS['dbi']->rawQuery($sql, array($row['goods_id'], $row['goods_id']));
			$_del_str = $id . ',';
			foreach ($res as $id_alone_sale_goods) {
				$_del_str .= $id_alone_sale_goods['rec_id'] . ',';
			}
			$_del_str = trim($_del_str, ',');

			$GLOBALS['dbi']->where('session_id', SESS_ID)->where('rec_id IN (?) OR parent_id = ? OR is_gift <> 0', array($_del_str, $row['goods_id']))->delete('cart');
		}

		//如果不是普通商品，只删除该商品即可
		else {
			$GLOBALS['dbi']->where('session_id', SESS_ID)->where('rec_id', $id)->delete('cart', 1);
		}
	}

	flow_clear_cart_alone();
}

/**
 * 删除购物车中不能单独销售的商品
 *
 * @access  public
 * @return  void
 */
function flow_clear_cart_alone() {
	/* 查询：购物车中所有不可以单独销售的配件 */
	$sql = "SELECT c.`rec_id`, gg.`parent_id`
            FROM " . $GLOBALS['ecs']->table('cart') . " AS c
                LEFT JOIN " . $GLOBALS['ecs']->table('group_goods') . " AS gg ON c.goods_id = gg.goods_id
                LEFT JOIN" . $GLOBALS['ecs']->table('goods') . " AS g ON c.goods_id = g.goods_id
            WHERE c.session_id = ?
            AND c.extension_code <> 'package_buy'
            AND gg.parent_id > 0
            AND g.is_alone_sale = 0";
	$res = $GLOBALS['dbi']->rawQuery($sql, array(SESS_ID));
	$rec_id = array();
	foreach ($res as $row) {
		$rec_id[$row['rec_id']][] = $row['parent_id'];
	}

	if (empty($rec_id)) {
		return;
	}

	/* 查询：购物车中所有商品 */
	$sql = "SELECT DISTINCT goods_id
            FROM " . $GLOBALS['ecs']->table('cart') . "
            WHERE session_id = ?
            AND extension_code <> 'package_buy'";
	$res = $GLOBALS['dbi']->rawQuery($sql, array(SESS_ID));
	$cart_good = array();
	foreach ($res as $row) {
		$cart_good[] = $row['goods_id'];
	}

	if (empty($cart_good)) {
		return;
	}

	/* 如果购物车中不可以单独销售配件的基本件不存在则删除该配件 */
	$del_rec_id = array();
	foreach ($rec_id as $key => $value) {
		foreach ($value as $v) {
			if (in_array($v, $cart_good)) {
				continue 2;
			}
		}

		$del_rec_id[] = $key;
	}

	if (empty($del_rec_id)) {
		return;
	}

	/* 删除 */
	$GLOBALS['dbi']->where('session_id', SESS_ID)->where('rec_id', $del_rec_id, 'IN')->delete('cart');
}

/**
 * 比较优惠活动的函数，用于排序（把可用的排在前面）
 * @param   array   $a      优惠活动a
 * @param   array   $b      优惠活动b
 * @return  int     相等返回0，小于返回-1，大于返回1
 */
function cmp_favourable($a, $b) {
	if ($a['available'] == $b['available']) {
		if ($a['sort_order'] == $b['sort_order']) {
			return 0;
		} else {
			return $a['sort_order'] < $b['sort_order'] ? -1 : 1;
		}
	} else {
		return $a['available'] ? -1 : 1;
	}
}

/**
 * 取得某用户等级当前时间可以享受的优惠活动
 * @param   int     $user_rank      用户等级id，0表示非会员
 * @return  array
 */
function favourable_list($user_rank) {
	/* 购物车中已有的优惠活动及数量 */
	$used_list = cart_favourable();

	/* 当前用户可享受的优惠活动 */
	$favourable_list = array();
	$user_rank = ',' . $user_rank . ',';
	$now = gmtime();

	$res = $GLOBALS['dbi']->where("CONCAT(',', user_rank, ',') LIKE ?", array('%' . $user_rank . '%'))->where('start_time', $now, '<=')->where('end_time', $now, '>=')->where('act_type', FAT_GOODS)->orderBy('sort_order', 'ASC')->get('favourable_activity');
	foreach ($res as $favourable) {
		$favourable['start_time'] = local_date($GLOBALS['_CFG']['time_format'], $favourable['start_time']);
		$favourable['end_time'] = local_date($GLOBALS['_CFG']['time_format'], $favourable['end_time']);
		$favourable['formated_min_amount'] = price_format($favourable['min_amount'], false);
		$favourable['formated_max_amount'] = price_format($favourable['max_amount'], false);
		$favourable['gift'] = unserialize($favourable['gift']);

		foreach ($favourable['gift'] as $key => $value) {
			$favourable['gift'][$key]['formated_price'] = price_format($value['price'], false);
			$is_sale = $GLOBALS['dbi']->where('is_on_sale', 1)->where('goods_id', $value['id'])->getValue('goods', 'COUNT(*)');
			if (!$is_sale) {
				unset($favourable['gift'][$key]);
			}
		}

		$favourable['act_range_desc'] = act_range_desc($favourable);
		$favourable['act_type_desc'] = sprintf($GLOBALS['_LANG']['fat_ext'][$favourable['act_type']], $favourable['act_type_ext']);

		/* 是否能享受 */
		$favourable['available'] = favourable_available($favourable);
		if ($favourable['available']) {
			/* 是否尚未享受 */
			$favourable['available'] = !favourable_used($favourable, $used_list);
		}

		$favourable_list[] = $favourable;
	}

	return $favourable_list;
}

/**
 * 根据购物车判断是否可以享受某优惠活动
 * @param   array   $favourable     优惠活动信息
 * @return  bool
 */
function favourable_available($favourable) {
	/* 会员等级是否符合 */
	$user_rank = $_SESSION['user_rank'];
	if (strpos(',' . $favourable['user_rank'] . ',', ',' . $user_rank . ',') === false) {
		return false;
	}

	/* 优惠范围内的商品总额 */
	$amount = cart_favourable_amount($favourable);

	/* 金额上限为0表示没有上限 */
	return $amount >= $favourable['min_amount'] &&
		($amount <= $favourable['max_amount'] || $favourable['max_amount'] == 0);
}

/**
 * 取得优惠范围描述
 * @param   array   $favourable     优惠活动
 * @return  string
 */
function act_range_desc($favourable) {
	$id_list = explode(',', $favourable['act_range_ext']);
	if ($favourable['act_range'] == FAR_BRAND) {
		return join(',', $GLOBALS['dbi']->where('brand_id', $id_list, 'IN')->getValue('brand', 'brand_name', null));
	} elseif ($favourable['act_range'] == FAR_CATEGORY) {
		return join(',', $GLOBALS['dbi']->where('cat_id', $id_list, 'IN')->getValue('category', 'cat_name', null));
	} elseif ($favourable['act_range'] == FAR_GOODS) {
		return join(',', $GLOBALS['dbi']->where('goods_id', $id_list, 'IN')->getValue('goods', 'goods_name', null));
	} else {
		return '';
	}
}

/**
 * 取得购物车中已有的优惠活动及数量
 * @return  array
 */
function cart_favourable() {
	$list = array();
	$res = $GLOBALS['dbi']->where('session_id', SESS_ID)->where('rec_type', CART_GENERAL_GOODS)->where('is_gift', 0, '>')->groupBy('is_gift')->get('cart', null, 'is_gift, COUNT(*) AS num');
	foreach ($res as $row) {
		$list[$row['is_gift']] = $row['num'];
	}

	return $list;
}

/**
 * 购物车中是否已经有某优惠
 * @param   array   $favourable     优惠活动
 * @param   array   $cart_favourable购物车中已有的优惠活动及数量
 */
function favourable_used($favourable, $cart_favourable) {
	if ($favourable['act_type'] == FAT_GOODS) {
		return isset($cart_favourable[$favourable['act_id']]) &&
		$cart_favourable[$favourable['act_id']] >= $favourable['act_type_ext'] &&
		$favourable['act_type_ext'] > 0;
	} else {
		return isset($cart_favourable[$favourable['act_id']]);
	}
}

/**
 * 添加优惠活动（赠品）到购物车
 * @param   int     $act_id     优惠活动id
 * @param   int     $id         赠品id
 * @param   float   $price      赠品价格
 */
function add_gift_to_cart($act_id, $id, $price) {
	$sql = "INSERT INTO " . $GLOBALS['ecs']->table('cart') . " (user_id, session_id, goods_id, goods_sn, goods_name, market_price, goods_price, goods_number, is_real, extension_code, parent_id, is_gift, rec_type ) SELECT ?, ?, goods_id, goods_sn, goods_name, market_price, ?, 1, is_real, extension_code, 0, ?, ? FROM " . $GLOBALS['ecs']->table('goods') . " WHERE goods_id = ?";
	$GLOBALS['dbi']->rawQuery($sql, array($_SESSION['user_id'], SESS_ID, $price, $act_id, CART_GENERAL_GOODS, $id));
}

/**
 * 添加优惠活动（非赠品）到购物车
 * @param   int     $act_id     优惠活动id
 * @param   string  $act_name   优惠活动name
 * @param   float   $amount     优惠金额
 */
function add_favourable_to_cart($act_id, $act_name, $amount) {
	$GLOBALS['dbi']->insert('cart', array(
		'user_id' => $_SESSION['user_id'],
		'session_id' => SESS_ID,
		'goods_id' => 0,
		'goods_sn' => "''",
		'goods_name' => $act_name,
		'market_price' => 0,
		'goods_price' => (-1) * $amount,
		'goods_number' => 1,
		'is_real' => 0,
		'extension_code' => "''",
		'parent_id' => 0,
		'is_gift' => $act_id,
		'rec_type' => CART_GENERAL_GOODS,
	));
}

/**
 * 取得购物车中某优惠活动范围内的总金额
 * @param   array   $favourable     优惠活动
 * @return  float
 */
function cart_favourable_amount($favourable) {
	/* 查询优惠范围内商品总额的sql */
	$GLOBALS['dbi']->join('goods g', 'c.goods_id = g.goods_id', 'INNER')->where('c.session_id', SESS_ID)->where('c.rec_type', CART_GENERAL_GOODS)->where('c.is_gift', 0)->where('c.goods_id', 0, '>');
	/* 根据优惠范围修正sql */
	if ($favourable['act_range'] == FAR_ALL) {
		// sql do not change
	} elseif ($favourable['act_range'] == FAR_CATEGORY) {
		/* 取得优惠范围分类的所有下级分类 */
		$id_list = array();
		$cat_list = explode(',', $favourable['act_range_ext']);
		foreach ($cat_list as $id) {
			$id_list = array_merge($id_list, array_keys(cat_list(intval($id), 0, false)));
		}
		$GLOBALS['dbi']->where('g.cat_id', $id_list, 'IN');
	} elseif ($favourable['act_range'] == FAR_BRAND) {
		$id_list = explode(',', $favourable['act_range_ext']);
		$GLOBALS['dbi']->where('g.brand_id', $id_list, 'IN');
	} else {
		$id_list = explode(',', $favourable['act_range_ext']);
		$GLOBALS['dbi']->where('g.goods_id', $id_list, 'IN');
	}

	/* 优惠范围内的商品总额 */
	return $GLOBALS['dbi']->getValue('cart c', 'IFNULL(SUM(c.goods_price * c.goods_number),0)');
}

?>
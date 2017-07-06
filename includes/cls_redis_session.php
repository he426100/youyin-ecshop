<?php
// change by vohn 21406360@qq.com  2015-12-10
if (!defined('IN_ECS')) {
	die('Hacking attempt');
}

/*------------------------------------------------------ */
//-- 该类用于将SESSION直接写入redis
/*------------------------------------------------------ */
class cls_Redis_session {
	var $db = NULL;

	var $max_life_time = 1800; // SESSION 过期时间

	var $session_name = '';
	var $session_id = '';

	var $session_expiry = '';
	var $session_md5 = '';

	var $session_cookie_path = '/';
	var $session_cookie_domain = '';
	var $session_cookie_secure = false;

	var $_ip = '';
	var $_time = 0;
	var $op = array();

	function __construct($db1, $session_table, $session_data_table, $session_name = 'ECS_ID', $session_id = '') {

		$host = '127.0.0.1';
		$port = 6379; //默认端口为11211
		$timeout = false; //过期时间
		$time = 3600; //过期时间
		$pre = "ECS_";
		$user = "";
		$pwd = "";

		if (empty($options)) {
			$options = array(
				'host' => $host ? $host : '127.0.0.1',
				'port' => $port ? $port : 6379,
				'timeout' => $timeout ? $timeout : false,
				'persistent' => false,
				'expire' => $time,
				'length' => 0,
				'pre' => $pre,
			);
		}
		$this->op = $options;

		$func = $options['persistent'] ? 'pconnect' : 'connect';
		$red = new redis;

		$conn = $red->$func($options['host'], $options['port']);
		$red->select(1);
		//if ($user) 		$this->red->auth($user . ":" . $pwd) ;

		$this->cls_session($red, $session_name, $session_id);
	}

	function cls_session($db1, $session_name = 'ECS_ID', $session_id = '') {
		$GLOBALS['_SESSION'] = array();

		if (!empty($GLOBALS['cookie_path'])) {
			$this->session_cookie_path = $GLOBALS['cookie_path'];
		} else {
			$this->session_cookie_path = '/';
		}

		if (!empty($GLOBALS['cookie_domain'])) {
			$this->session_cookie_domain = $GLOBALS['cookie_domain'];
		} else {
			$this->session_cookie_domain = '';
		}

		if (!empty($GLOBALS['cookie_secure'])) {
			$this->session_cookie_secure = $GLOBALS['cookie_secure'];
		} else {
			$this->session_cookie_secure = false;
		}

		$this->session_name = $session_name;

		$this->db = &$db1;
		$this->_ip = real_ip();

		if ($session_id == '' && !empty($_COOKIE[$this->session_name])) {
			$this->session_id = $_COOKIE[$this->session_name];
		} else {
			$this->session_id = $session_id;
		}

		if ($this->session_id) {
			$tmp_session_id = substr($this->session_id, 0, 32);
			if ($this->gen_session_key($tmp_session_id) == substr($this->session_id, 32)) {
				$this->session_id = $tmp_session_id;
			} else {
				$this->session_id = '';
			}
		}

		$this->_time = time();

		if ($this->session_id) {
			$this->load_session();

		} else {
			$this->gen_session_id();
			setcookie($this->session_name, $this->session_id . $this->gen_session_key($this->session_id), 0, $this->session_cookie_path, $this->session_cookie_domain, $this->session_cookie_secure);
		}
		register_shutdown_function(array(&$this, 'close_session'));
	}

	function gen_session_id() {
		$this->session_id = md5(uniqid(mt_rand(), true));

		return $this->insert_session();
	}

	function gen_session_key($session_id) {
		static $ip = '';

		if ($ip == '') {
			$ip = substr($this->_ip, 0, strrpos($this->_ip, '.'));
		}

		return sprintf('%08x', crc32(!empty($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] . ROOT_PATH . $ip . $session_id : ROOT_PATH . $ip . $session_id));
	}

	function insert_session() {
		$value = array('expiry' => $this->_time, 'ip' => $this->_ip, 'data' => 'a:0:{}');
		$value = (is_object($value) || is_array($value)) ? json_encode($value) : $value;

		//print_r($value);
		//exit();
		return $this->db->set('SESS_' . $this->session_id, $value, $this->max_life_time);
	}

	function load_session() {
		$session2 = $this->db->get('SESS_' . $this->session_id);

		if (empty($session2)) {
			$this->insert_session();
			$this->session_expiry = 0;
			$this->session_md5 = '40cd750bba9870f18aada2478b24840a';
			$GLOBALS['_SESSION'] = array();
		} else {
			$session = json_decode($session2, true);
			if (!empty($session['data']) && $this->_time - $session['expiry'] <= $this->max_life_time) {
				$this->session_expiry = $session['expiry'];
				$this->session_md5 = md5($session['data']);

				$GLOBALS['_SESSION'] = json_decode($session['data'], true);

				$GLOBALS['_SESSION']['user_id'] = $session['userid'];
				$GLOBALS['_SESSION']['admin_id'] = $session['adminid'];
				$GLOBALS['_SESSION']['user_name'] = $session['user_name'];
				$GLOBALS['_SESSION']['user_rank'] = $session['user_rank'];
				$GLOBALS['_SESSION']['discount'] = $session['discount'];
				$GLOBALS['_SESSION']['email'] = $session['email'];
				//$GLOBALS['_SESSION']  = json_decode($session->data);

				//$GLOBALS['_SESSION']  = unserialize(stripslashes($session['data']));
			} else {

				$this->session_expiry = 0;
				$this->session_md5 = '40cd750bba9870f18aada2478b24840a';
				$GLOBALS['_SESSION'] = array();
			}
		}

	}

	function update_session() {

		$adminid = !empty($GLOBALS['_SESSION']['admin_id']) ? intval($GLOBALS['_SESSION']['admin_id']) : 0;
		$userid = !empty($GLOBALS['_SESSION']['user_id']) ? intval($GLOBALS['_SESSION']['user_id']) : 0;
		$user_name = !empty($GLOBALS['_SESSION']['user_name']) ? trim($GLOBALS['_SESSION']['user_name']) : 0;
		$user_rank = !empty($GLOBALS['_SESSION']['user_rank']) ? intval($GLOBALS['_SESSION']['user_rank']) : 0;
		$discount = !empty($GLOBALS['_SESSION']['discount']) ? round($GLOBALS['_SESSION']['discount'], 2) : 0;
		$email = !empty($GLOBALS['_SESSION']['email']) ? trim($GLOBALS['_SESSION']['email']) : 0;
		unset($GLOBALS['_SESSION']['admin_id']);
		unset($GLOBALS['_SESSION']['user_id']);
		unset($GLOBALS['_SESSION']['user_name']);
		unset($GLOBALS['_SESSION']['user_rank']);
		unset($GLOBALS['_SESSION']['discount']);
		unset($GLOBALS['_SESSION']['email']);
		$data = json_encode($GLOBALS['_SESSION']);

		//$adminid = !empty($GLOBALS['_SESSION']['admin_id']) ? intval($GLOBALS['_SESSION']['admin_id']) : 0;
		//$userid  = !empty($GLOBALS['_SESSION']['user_id'])  ? intval($GLOBALS['_SESSION']['user_id'])  : 0;

		//$data = serialize($GLOBALS['_SESSION']);
		$this->_time = time();

		if ($this->session_md5 == md5($data) && $this->_time < $this->session_expiry + 10) {
			return true;
		}

		//$data = addslashes($data);

		$value = array('expiry' => $this->_time, 'ip' => $this->_ip, 'userid' => $userid, 'adminid' => $adminid, 'user_name' => $user_name, 'user_rank' => $user_rank, 'discount' => $discount, 'email' => $email, 'data' => $data);
		$value1 = (is_object($value) || is_array($value)) ? json_encode($value) : $value;

		$this->db->set('SESS_' . $this->session_id, $value1, $this->max_life_time);

		return;
	}

	function close_session() {

		$this->update_session();
		return true;
	}

	function delete_spec_admin_session($adminid) {
		if (!empty($GLOBALS['_SESSION']['admin_id']) && $adminid) {

			$this->db->flushDB();
		} else {
			return false;
		}
	}

	function destroy_session() {
		$GLOBALS['_SESSION'] = array();

		setcookie($this->session_name, $this->session_id, 1, $this->session_cookie_path, $this->session_cookie_domain, $this->session_cookie_secure);

		/* ECSHOP 自定义执行部分 */
		if (!empty($GLOBALS['ecs'])) {
			$GLOBALS['db']->query('DELETE FROM ' . $GLOBALS['ecs']->table('cart') . " WHERE session_id = '$this->session_id'");
		}
		/* ECSHOP 自定义执行部分 */

		return $this->db->delete('SESS_' . $this->session_id);
	}

	function get_session_id() {
		return $this->session_id;
	}

	function get_users_count() {
		$all_items = $this->db->DBSIZE();
		return $count = $all_items; //由于有其他key的缓存，因此这只是个接近数值
	}

}

?>
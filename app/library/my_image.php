<?php
include EC_PATH.'/includes/cls_image.php';
/**
 * base64方式上传图片
 */
class my_image extends cls_image{
	function __construct($bgcolor = ''){
		parent::__construct($bgcolor);
	}
	/**
	 * 图片上传的处理函数
	 * @access public
	 * @param string upload 包含上传的图片文件信息的base64字符串
	 * @param array dir 文件要上传在$this->data_dir下的目录名。如果为空图片放在则在$this->images_dir下以当月命名的目录下
	 * @param array img_name 上传图片名称，为空则随机生成
	 * @return mix 如果成功则返回文件名，否则返回false
	 */
	function upload_image($upload, $dir = '', $img_name = '') {
		if(!preg_match('/^(data:\s*image\/(\w+);base64,)/', $upload, $result)){
			return false;
		}
		/* 没有指定目录默认为根目录images */
		if (empty($dir)) {
			/* 创建当月目录 */
			$dir = date('Ym');
			$dir = ROOT_PATH . $this->images_dir . '/' . $dir . '/';
		} else {
			/* 创建目录 */
			$dir = ROOT_PATH . $this->data_dir . '/' . $dir . '/';
			if ($img_name) {
				$img_name = $dir . $img_name; // 将图片定位到正确地址
			}
		}
		/* 如果目标目录不存在，则创建它 */
		if (!file_exists($dir)) {
			if (!make_dir($dir)) {
				/* 创建目录失败 */
				$this->error_msg = sprintf($GLOBALS['_LANG']['directory_readonly'], $dir);
				$this->error_no = ERR_DIRECTORY_READONLY;

				return false;
			}
		}
		/* 生成文件名 */
		if (empty($img_name)) {
			$img_name = $this->unique_name($dir);
			$img_name = $dir . $img_name . '.' . $result[2];
		}
		/* 保存base64内容为图片文件 */
		if (!file_put_contents($img_name, base64_decode(str_replace($result[1], '', $upload)))) {
			$this->error_msg = sprintf($GLOBALS['_LANG']['upload_failure'], str_replace(ROOT_PATH, '', $img_name));
			$this->error_no = ERR_UPLOAD_FAILURE;
			return false;
		}
		/* 检查文件内容，不通过则尝试删除图片 */
		if (!$this->check_img_type('image/'.$result[2])) {
			@unlink($img_name);
			$this->error_msg = $GLOBALS['_LANG']['invalid_upload_image_type'];
			$this->error_no = ERR_INVALID_IMAGE_TYPE;
			return false;
		}
		/* 允许上传的文件类型 */
		$allow_file_types = '|GIF|JPG|JPEG|PNG|BMP|SWF|';
		if (!check_file_type($img_name, $img_name, $allow_file_types)) {
			@unlink($img_name);
			$this->error_msg = $GLOBALS['_LANG']['invalid_upload_image_type'];
			$this->error_no = ERR_INVALID_IMAGE_TYPE;
			return false;
		}
		/* 最后判断文件是否正常，是则返回路径路径，否则尝试删除文件并返回错误信息 */
		if (is_file($img_name)) {
			return str_replace(ROOT_PATH, '', $img_name);
		} else {
			@unlink($img_name);
			$this->error_msg = sprintf($GLOBALS['_LANG']['upload_failure'], str_replace(ROOT_PATH, '', $img_name));
			$this->error_no = ERR_UPLOAD_FAILURE;
			return false;
		}
	}
}
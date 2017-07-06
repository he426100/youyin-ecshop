<?php
/**
 * ecshop 无限级上下级关系处理
 * 本函数库依赖 dbi
 */
/**
 * 新增上下级关系
 * @param integer $parent_id 上级id
 * @param integer $user_id   下级id
 */
function add_parent($parent_id, $user_id){
	$GLOBALS['dbi']->startTransaction();
	try{
		//建立跟上级的关系
		$GLOBALS['dbi']->insert('parent', array('parent_id' => $parent_id, 'user_id' => $user_id, 'level' => 1));
		//如果上级有上级，把我跟每一个上级建立关系
		$grandpas = $GLOBALS['dbi']->where('user_id', $parent_id)->get('parent', null, array('parent_id', 'level'));
		foreach ($grandpas as $parent){
			$GLOBALS['dbi']->insert('parent', array('parent_id' => $parent['parent_id'], 'user_id' => $user_id, 'level' => $parent['level']+1 )); 
		}
		//如果我有下级，把我所有下级跟我的上级建立关系
		$children = $GLOBALS['dbi']->where('parent_id', $user_id)->get('parent', null, array('user_id', 'level'));
		foreach ($children as $child){
			$GLOBALS['dbi']->insert('parent', array('parent_id' => $parent_id, 'user_id' => $child['user_id'], 'level' => $child['level']+1));
			//还要跟我的上级的所有上级建立关系
			foreach ($grandpas as $parent){
				$GLOBALS['dbi']->insert('parent', array('parent_id' => $parent['parent_id'], 'user_id' => $child['user_id'], 'level' => $child['level'] + $parent['level'] + 1 ));
			}
		}
		$GLOBALS['dbi']->commit();
	} catch(Exception $e){
		$GLOBALS['dbi']->rollback();
	}
}
/**
 * 更改上级
 * @param  integer $user_id  用户id
 * @param  integer $parent_id 新上级id，为0时仅去除原有上级
 */
function update_parent($user_id, $parent_id = 0){
	delete_parent($user_id);
	if($parent_id > 0){
		add_parent($parent_id, $user_id);
	}
}
/**
 * 去除上级
 * @param  integer $user_id  用户id
 */
function delete_parent($user_id){
	$GLOBALS['dbi']->startTransaction();
	try{
		//先跟原来的上级们断掉关系
		$GLOBALS['dbi']->where('user_id', $user_id)->delete('parent');
		//要断绝跟上级们的关系，还得加上我的下级们
		$children = $GLOBALS['dbi']->where('parent_id', $user_id)->get('parent', null, array('user_id', 'level'));
		foreach ($children as $child){
			//对每一个下级而言，跟“我”的关系不用变，但是在我之上的更远关系应该删除，无论更远的上级是谁
			$GLOBALS['dbi']->where('user_id', $child['user_id'])->where('level', array('>' => $child['level']))->delete('parent');
		}
		$GLOBALS['dbi']->commit();
	} catch(Exception $e){
		$GLOBALS['dbi']->rollback();
	}
}
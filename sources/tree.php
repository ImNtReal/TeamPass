<?php

declare(strict_types=1);

/**
 * Teampass - a collaborative passwords manager.
 * ---
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.
 *
 * @project   Teampass
 * @file      tree.php
 *
 * @author    Nils Laumaillé (nils@teampass.net)
 * @copyright 2009-2022 Teampass.net
 * @license   https://spdx.org/licenses/GPL-3.0-only.html#licenseText GPL-3.0
 *
 * @see       https://www.teampass.net
 */


require_once 'SecureHandler.php';
session_name('teampass_session');
session_start();
if (
    isset($_SESSION['CPM']) === false
    || $_SESSION['CPM'] !== 1
    || isset($_SESSION['key']) === false
    || empty($_SESSION['key']) === true
) {
    die('Hacking attempt...');
}

// Load config
if (file_exists('../includes/config/tp.config.php')) {
    include_once '../includes/config/tp.config.php';
} elseif (file_exists('./includes/config/tp.config.php')) {
    include_once './includes/config/tp.config.php';
} else {
    throw new Exception("Error file '/includes/config/tp.config.php' not exists", 1);
}

// includes
require_once $SETTINGS['cpassman_dir'] . '/includes/config/include.php';
require_once $SETTINGS['cpassman_dir'] . '/sources/SplClassLoader.php';
require_once $SETTINGS['cpassman_dir'] . '/sources/main.functions.php';
require_once $SETTINGS['cpassman_dir'] . '/includes/language/' . $_SESSION['user_language'] . '.php';
require_once $SETTINGS['cpassman_dir'] . '/includes/config/settings.php';

// header
header('Content-type: text/html; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

// Define Timezone
if (isset($SETTINGS['timezone'])) {
    date_default_timezone_set($SETTINGS['timezone']);
} else {
    date_default_timezone_set('UTC');
}

// Connect to mysql server
require_once $SETTINGS['cpassman_dir'] . '/includes/libraries/Database/Meekrodb/db.class.php';
if (defined('DB_PASSWD_CLEAR') === false) {
    define('DB_PASSWD_CLEAR', defuseReturnDecrypted(DB_PASSWD, $SETTINGS));
}
DB::$host = DB_HOST;
DB::$user = DB_USER;
DB::$password = DB_PASSWD_CLEAR;
DB::$dbName = DB_NAME;
DB::$port = DB_PORT;
DB::$encoding = DB_ENCODING;

// Superglobal load
require_once $SETTINGS['cpassman_dir'] . '/includes/libraries/protect/SuperGlobal/SuperGlobal.php';
$superGlobal = new protect\SuperGlobal\SuperGlobal();
$get = [];
$get['user_tree_structure'] = $superGlobal->get('user_tree_structure', 'GET');
$get['user_tree_last_refresh_timestamp'] = $superGlobal->get('user_tree_last_refresh_timestamp', 'GET');
$get['force_refresh'] = $superGlobal->get('force_refresh', 'GET');
$get['id'] = $superGlobal->get('id', 'GET');
$session = [];
$session['forbiden_pfs'] = $superGlobal->get('forbiden_pfs', 'SESSION');
$session['groupes_visibles'] = $superGlobal->get('groupes_visibles', 'SESSION');
$session['list_restricted_folders_for_items'] = $superGlobal->get('list_restricted_folders_for_items', 'SESSION');
$session['user_id'] = $superGlobal->get('user_id', 'SESSION');
$session['login'] = $superGlobal->get('login', 'SESSION');
$session['user_read_only'] = $superGlobal->get('user_read_only', 'SESSION');
//$session['personal_folder'] = explode(';', $superGlobal->get('personal_folder', 'SESSION'));
$session['list_folders_limited'] = $superGlobal->get('list_folders_limited', 'SESSION');
$session['read_only_folders'] = $superGlobal->get('read_only_folders', 'SESSION');
$session['personal_visible_groups'] = $superGlobal->get('personal_visible_groups', 'SESSION');
$lastFolderChange = DB::query(
    'SELECT * FROM ' . prefixTable('misc') . '
    WHERE type = %s AND intitule = %s',
    'timestamp',
    'last_folder_change'
);
if (
    empty($get['user_tree_structure']) === true
    || ($get['user_tree_last_refresh_timestamp'] !== null && strtotime($lastFolderChange) > strtotime($get['user_tree_last_refresh_timestamp']))
    || (isset($get['force_refresh']) === true && (int) $get['force_refresh'] === 1)
) {
    // Build tree
    $tree = new SplClassLoader('Tree\NestedTree', $SETTINGS['cpassman_dir'] . '/includes/libraries');
    $tree->register();
    $tree = new Tree\NestedTree\NestedTree(prefixTable('nested_tree'), 'id', 'parent_id', 'title');

    if (
        isset($session['list_folders_limited']) === true
        && is_array($session['list_folders_limited']) === true
        && count($session['list_folders_limited']) > 0
    ) {
        $listFoldersLimitedKeys = array_keys($session['list_folders_limited']);
    } else {
        $listFoldersLimitedKeys = array();
    }
    
    // list of items accessible but not in an allowed folder
    if (
        isset($session['list_restricted_folders_for_items']) === true
        && count($session['list_restricted_folders_for_items']) > 0
    ) {
        $listRestrictedFoldersForItemsKeys = @array_keys($session['list_restricted_folders_for_items']);
    } else {
        $listRestrictedFoldersForItemsKeys = array();
    }
    
    //$_SESSION['user_treeloadstrategy'] = 'sequential';
    $ret_json = array();
    $last_visible_parent = -1;
    $last_visible_parent_level = 1;

    // build the tree to be displayed
    if (
        isset($get['id']) === true
        && is_numeric(intval($get['id'])) === true
        && isset($_SESSION['user_treeloadstrategy']) === true
        && $_SESSION['user_treeloadstrategy'] === 'sequential'
    ) {
        $ret_json = buildNodeTree(
            $get['id'],
            $listFoldersLimitedKeys,
            $listRestrictedFoldersForItemsKeys,
            /** @scrutinizer ignore-type */ $tree,
            $SETTINGS,
            $session['forbiden_pfs'],
            $session['groupes_visibles'],
            $session['list_restricted_folders_for_items'],
            $session['user_id'],
            $session['login'],
            $superGlobal->get('no_access_folders', 'SESSION'),
            $superGlobal->get('personal_folders', 'SESSION'),
            $session['list_folders_limited'],
            $session['read_only_folders'],
            $superGlobal->get('personal_folders', 'SESSION'),
            $session['personal_visible_groups'],
            $session['user_read_only']
        );
    } elseif (
        isset($_SESSION['user_treeloadstrategy']) === true
        && $_SESSION['user_treeloadstrategy'] === 'sequential'
    ) {
        $ret_json = buildNodeTree(
            0,
            $listFoldersLimitedKeys,
            $listRestrictedFoldersForItemsKeys,
            /** @scrutinizer ignore-type */ $tree,
            $SETTINGS,
            $session['forbiden_pfs'],
            $session['groupes_visibles'],
            $session['list_restricted_folders_for_items'],
            $session['user_id'],
            $session['login'],
            $superGlobal->get('no_access_folders', 'SESSION'),
            $superGlobal->get('personal_folders', 'SESSION'),
            $session['list_folders_limited'],
            $session['read_only_folders'],
            $superGlobal->get('personal_folders', 'SESSION'),
            $session['personal_visible_groups'],
            $session['user_read_only']
        );
    } else {
        $completTree = $tree->getTreeWithChildren();
        foreach ($completTree[0]->children as $child) {
            recursiveTree(
                $child,
                $completTree,
                /** @scrutinizer ignore-type */ $tree,
                $listFoldersLimitedKeys,
                $listRestrictedFoldersForItemsKeys,
                $last_visible_parent,
                $last_visible_parent_level,
                $SETTINGS,
                $session['forbiden_pfs'],
                $session['groupes_visibles'],
                $session['list_restricted_folders_for_items'],
                $session['user_id'],
                $session['login'],
                $session['user_read_only'],
                $superGlobal->get('personal_folders', 'SESSION'),
                $session['list_folders_limited'],
                $session['read_only_folders'],
                $session['personal_visible_groups'],
                $superGlobal->get('can_create_root_folder', 'SESSION'),
                $ret_json
            );
        }
    }

    // Save in SESSION
    $superGlobal->put('user_tree_structure', $ret_json, 'SESSION');
    $superGlobal->put('user_tree_last_refresh_timestamp', time(), 'SESSION');

    // Send back
    echo json_encode($ret_json);
} else {
    echo $get['user_tree_structure'];
}

/**
 * Get through asked folders
 *
 * @param int $nodeId
 * @param array $listFoldersLimitedKeys
 * @param array $listRestrictedFoldersForItemsKeys
 * @param array $tree
 * @param array $SETTINGS
 * @param array $session_forbiden_pfs
 * @param array $session_groupes_visibles
 * @param array $session_list_restricted_folders_for_items
 * @param int $session_user_id
 * @param string $session_login
 * @param array $session_no_access_folders
 * @param array $session_list_folders_limited
 * @param array $session_read_only_folders
 * @param array $session_personal_folders
 * @param array $session_personal_visible_groups
 * @param int $session_user_read_only
 * @return array
 */
function buildNodeTree(
    $nodeId,
    $listFoldersLimitedKeys,
    $listRestrictedFoldersForItemsKeys,
    $tree,
    $SETTINGS,
    $session_forbiden_pfs,
    $session_groupes_visibles,
    $session_list_restricted_folders_for_items,
    $session_user_id,
    $session_login,
    $session_no_access_folders,
    $session_list_folders_limited,
    $session_read_only_folders,
    $session_personal_folders,
    $session_personal_visible_groups,
    $session_user_read_only
) {
    // Prepare superGlobal variables
    $ret_json = array();

    // Be sure that user can only see folders he/she is allowed to
    if (
        in_array($nodeId, $session_forbiden_pfs) === false
        || in_array($nodeId, $session_groupes_visibles) === true
        || in_array($nodeId, $listFoldersLimitedKeys) === true
        || in_array($nodeId, $listRestrictedFoldersForItemsKeys) === true
    ) {
        $nbChildrenItems = 0;

        // Check if any allowed folder is part of the descendants of this node
        $nodeDescendants = $tree->getDescendants($nodeId, false, true, false);
        foreach ($nodeDescendants as $node) {
            if ((in_array($node->id, $session_forbiden_pfs) === false
                    || in_array($node->id, $session_groupes_visibles) === true
                    || in_array($node->id, $listFoldersLimitedKeys) === true
                    || in_array($node->id, $listRestrictedFoldersForItemsKeys)) === true
                && (in_array(
                    $node->id,
                    array_merge($session_groupes_visibles, $session_list_restricted_folders_for_items)
                ) === true
                    || (is_array($listFoldersLimitedKeys) === true && in_array($node->id, $listFoldersLimitedKeys) === true)
                    || (is_array($listRestrictedFoldersForItemsKeys) === true && in_array($node->id, $listRestrictedFoldersForItemsKeys) === true)
                    || in_array($node->id, $session_no_access_folders) === true)
            ) {
                $nodeElements= buildNodeTreeElements(
                    $node,
                    $session_user_id,
                    $session_login,
                    $session_user_read_only,
                    $session_personal_folders,
                    $session_groupes_visibles,
                    $session_read_only_folders,
                    $session_personal_visible_groups,
                    $nbChildrenItems,
                    $nodeDescendants,
                    $listFoldersLimitedKeys,
                    $session_list_folders_limited,
                    $listRestrictedFoldersForItemsKeys,
                    $session_list_restricted_folders_for_items,
                    $SETTINGS,
                    $tree->numDescendants($node->id)
                );

                // json    
                array_push(
                    $ret_json,
                    array(
                        'id' => 'li_' . $node->id,
                        'parent' => $nodeElements['parent'],
                        'text' => '<i class="fas fa-folder mr-2"></i>'.($nodeElements['show_but_block'] === true ? '<i class="fas fa-times fa-xs text-danger mr-1"></i>' : '') . $nodeElements['text'],
                        'children' => ($nodeElements['childrenNb'] === 0 ? false : true),
                        'fa_icon' => 'folder',
                        'li_attr' => array(
                            'class' => ($nodeElements['show_but_block'] === true ? '' : 'jstreeopen'),
                            'title' => 'ID [' . $node->id . '] ' . ($nodeElements['show_but_block'] === true ? langHdl('no_access') : $nodeElements['title']),
                        ),
                        'a_attr' => $nodeElements['show_but_block'] === true ? (array(
                            'id' => 'fld_' . $node->id,
                            'class' => $nodeElements['folderClass'],
                            'onclick' => 'ListerItems(' . $node->id . ', ' . $nodeElements['restricted'] . ', 0, 1)',
                            'data-title' => $node->title,
                        )) : '',
                        'is_pf' => in_array($node->id, $session_personal_folders) === true ? 1 : 0,
                    )
                );
            }
        }
    }
    return $ret_json;
}


function buildNodeTreeElements(
    $node,
    $session_user_id,
    $session_login,
    $session_user_read_only,
    $session_personal_folders,
    $session_groupes_visibles,
    $session_read_only_folders,
    $session_personal_visible_groups,
    $nbChildrenItems,
    $nodeDescendants,
    $listFoldersLimitedKeys,
    $session_list_folders_limited,
    $listRestrictedFoldersForItemsKeys,
    $session_list_restricted_folders_for_items,
    $SETTINGS,
    $numDescendants
)
{
    // get count of Items in this folder
    DB::query(
        'SELECT *
        FROM ' . prefixTable('items') . '
        WHERE inactif=%i AND id_tree = %i',
        0,
        $node->id
    );
    $itemsNb = DB::count();

    // get info about current folder
    DB::query(
        'SELECT *
        FROM ' . prefixTable('nested_tree') . '
        WHERE parent_id = %i',
        $node->id
    );
    $childrenNb = DB::count();

    // If personal Folder, convert id into user name
    $node->title = $node->title === $session_user_id && (int) $node->nlevel === 1 ?
        $session_login :
        ($node->title === null ? '' : htmlspecialchars_decode($node->title, ENT_QUOTES));

    // prepare json return for current node
    $parent = $node->parent_id === 0 ? '#' : 'li_' . $node->parent_id;

    // special case for READ-ONLY folder
    $title = (bool) $session_user_read_only === true && in_array($node->id, $session_personal_folders) === false ? langHdl('read_only_account') : '';
    $text = str_replace('&', '&amp;', $node->title);
    //$restricted = 0;
    //$folderClass = 'folder';
    //$show_but_block = false;

    if (in_array($node->id, $session_groupes_visibles) === true) {
        if (in_array($node->id, $session_read_only_folders) === true) {
            return array(
                'text' => '<i class="far fa-eye fa-xs mr-1 ml-1"></i>' . $text
                    .' <span class=\'badge badge-danger ml-2 items_count\' id=\'itcount_' . $node->id . '\'>' . $itemsNb . '</span>'
                    .(isset($SETTINGS['tree_counters']) && (int) $SETTINGS['tree_counters'] === 1 ?
                        '/'.$nbChildrenItems .'/'.(count($nodeDescendants) - 1)
                        : '')
                    .'</span>',
                'title' => langHdl('read_only_account'),
                'restricted' => 1,
                'folderClass' => 'folder_not_droppable',
                'show_but_block' => false,
                'parent' => $parent,
                'childrenNb' => $childrenNb,
                'itemsNb' => $itemsNb,
            );
        }
        
        return array(
            'text' => ($session_user_read_only === true && in_array($node->id, $session_personal_visible_groups) === false) ?
                ('<i class="far fa-eye fa-xs mr-1 ml-1"></i>' . $text
                .' <span class=\'badge badge-danger ml-2 items_count\' id=\'itcount_' . $node->id . '\'>' . $itemsNb . '</span>'
                .(isset($SETTINGS['tree_counters']) && (int) $SETTINGS['tree_counters'] === 1 ?
                    '/'.$nbChildrenItems .'/'.(count($nodeDescendants) - 1)  :
                    '')
                .'</span>') :
                (' <span class=\'badge badge-danger ml-2 items_count\' id=\'itcount_' . $node->id . '\'>' . $itemsNb . '</span>'
                .(isset($SETTINGS['tree_counters']) && (int) $SETTINGS['tree_counters'] === 1 ?
                    '/'.$nbChildrenItems .'/'.(count($nodeDescendants) - 1)  :
                    '')
                .'</span>'),
            'title' => langHdl('read_only_account'),
            'restricted' => 1,
            'folderClass' => 'folder_not_droppable',
            'show_but_block' => false,
            'parent' => $parent,
            'childrenNb' => $childrenNb,
            'itemsNb' => $itemsNb,
        );
    }
    
    elseif (in_array($node->id, $listFoldersLimitedKeys) === true) {
        return array(
            'text' => $text . ($session_user_read_only === true ?
                '<i class="far fa-eye fa-xs mr-1 ml-1"></i>' :
                '<span class="badge badge-danger ml-2 items_count" id="itcount_' . $node->id . '">' . count($session_list_folders_limited[$node->id]) . '</span>'
            ),
            'title' => $title,
            'restricted' => 1,
            'folderClass' => 'folder',
            'show_but_block' => false,
            'parent' => $parent,
            'childrenNb' => $childrenNb,
            'itemsNb' => $itemsNb,
        );
    }
    
    elseif (in_array($node->id, $listRestrictedFoldersForItemsKeys) === true) {        
        return array(
            'text' => $text . ($session_user_read_only === true ? 
                '<i class="far fa-eye fa-xs mr-1 ml-1"></i>' :
                '<span class="badge badge-danger ml-2 items_count" id="itcount_' . $node->id . '">' . count($session_list_restricted_folders_for_items[$node->id]) . '</span>'
            ),
            'title' => $title,
            'restricted' => 1,
            'folderClass' => 'folder',
            'show_but_block' => false,
            'parent' => $parent,
            'childrenNb' => $childrenNb,
            'itemsNb' => $itemsNb,
        );
    }

    // default case
    elseif (isset($SETTINGS['show_only_accessible_folders']) === true
        && (int) $SETTINGS['show_only_accessible_folders'] === 1
        && (int) $numDescendants === 0)
    {
        return array();
    }

    return array(
        'text' => $text,
        'title' => $title,
        'restricted' => 1,
        'folderClass' => 'folder_not_droppable',
        'show_but_block' => true,   // folder is visible but not accessible by user
        'parent' => $parent,
        'childrenNb' => $childrenNb,
        'itemsNb' => $itemsNb,
    );
}

/**
 * Get through complete tree
 *
 * @param int     $nodeId                            Id
 * @param array   $completTree                       Tree info
 * @param array   $tree                              The tree
 * @param array   $listFoldersLimitedKeys            Limited
 * @param array   $listRestrictedFoldersForItemsKeys Restricted
 * @param int     $last_visible_parent               Visible parent
 * @param int     $last_visible_parent_level         Parent level
 * @param array   $SETTINGS                          Teampass settings
 * @param string  $session_forbiden_pfs,
 * @param string  $session_groupes_visibles,
 * @param string  $session_list_restricted_folders_for_items,
 * @param int     $session_user_id,
 * @param string  $session_login,
 * @param string  $session_user_read_only,
 * @param array   $session_personal_folder,
 * @param string  $session_list_folders_limited,
 * @param string  $session_read_only_folders,
 * @param string  $session_personal_visible_groups
 * @param int     $can_create_root_folder
 * @param array   $ret_json                          Array
 *
 * @return array
 */
function recursiveTree(
    $nodeId,
    $completTree,
    $tree,
    $listFoldersLimitedKeys,
    $listRestrictedFoldersForItemsKeys,
    $last_visible_parent,
    $last_visible_parent_level,
    $SETTINGS,
    $session_forbiden_pfs,
    $session_groupes_visibles,
    $session_list_restricted_folders_for_items,
    $session_user_id,
    $session_login,
    $session_user_read_only,
    $session_personal_folder,
    $session_list_folders_limited,
    $session_read_only_folders,
    $session_personal_visible_groups,
    $can_create_root_folder,
    &$ret_json = array()
) {
    $text = '';
    $title = '';
    $show_but_block = false;

    // Load config
    if (file_exists('../includes/config/tp.config.php')) {
        include '../includes/config/tp.config.php';
    } elseif (file_exists('./includes/config/tp.config.php')) {
        include './includes/config/tp.config.php';
    } else {
        throw new Exception("Error file '/includes/config/tp.config.php' not exists", 1);
    }
    
    // Be sure that user can only see folders he/she is allowed to
    if (
        in_array($completTree[$nodeId]->id, $session_forbiden_pfs) === false
        || in_array($completTree[$nodeId]->id, $session_groupes_visibles) === true
    ) {
        $displayThisNode = false;
        $nbChildrenItems = 0;
        $nodeDirectDescendants = $tree->getDescendants($completTree[$nodeId]->id, false, false, true);

        // Check if any allowed folder is part of the descendants of this node
        $nodeDescendants = $tree->getDescendants($completTree[$nodeId]->id, true, false, true);
        foreach ($nodeDescendants as $node) {
            // manage tree counters
            if (
                isset($SETTINGS['tree_counters']) === true
                && (int) $SETTINGS['tree_counters'] === 1
                && in_array(
                    $node,
                    array_merge($session_groupes_visibles, $session_list_restricted_folders_for_items)
                ) === true
            ) {
                DB::query(
                    'SELECT * FROM ' . prefixTable('items') . '
                    WHERE inactif=%i AND id_tree = %i',
                    0,
                    $node
                );
                $nbChildrenItems += DB::count();
            }

            if (
                in_array($node, $session_groupes_visibles) === true
            ) {
                // Final check - is PF allowed?
                $nodeDetails = $tree->getNode($node);
                if (
                    (int) $nodeDetails->personal_folder === 1
                    && (int) $SETTINGS['enable_pf_feature'] === 1
                    && in_array($node, $session_personal_folder) === false
                ) {
                    $displayThisNode = false;
                } else {
                    $displayThisNode = true;
                    // not adding a break in order to permit a correct count of items
                }
                $text = '';
            }
        }
        
        if ($displayThisNode === true) {
            handleNode(
                $nodeId,
                $completTree,
                $tree,
                $listFoldersLimitedKeys,
                $listRestrictedFoldersForItemsKeys,
                $last_visible_parent,
                $last_visible_parent_level,
                $SETTINGS,
                $session_forbiden_pfs,
                $session_groupes_visibles,
                $session_list_restricted_folders_for_items,
                $session_user_id,
                $session_login,
                $session_user_read_only,
                $session_personal_folder,
                $session_list_folders_limited,
                $session_read_only_folders,
                $session_personal_visible_groups,
                $text,
                $nbChildrenItems,
                $nodeDescendants,
                $nodeDirectDescendants,
                $can_create_root_folder,
                $ret_json
            );
        }
    }
    return $ret_json;
}


function handleNode(
    $nodeId,
    $completTree,
    $tree,
    $listFoldersLimitedKeys,
    $listRestrictedFoldersForItemsKeys,
    $last_visible_parent,
    $last_visible_parent_level,
    $SETTINGS,
    $session_forbiden_pfs,
    $session_groupes_visibles,
    $session_list_restricted_folders_for_items,
    $session_user_id,
    $session_login,
    $session_user_read_only,
    $session_personal_folder,
    $session_list_folders_limited,
    $session_read_only_folders,
    $session_personal_visible_groups,
    $text,
    $nbChildrenItems,
    $nodeDescendants,
    $nodeDirectDescendants,
    $can_create_root_folder, 
    &$ret_json = array()
)
{
    // get info about current folder
    DB::query(
        'SELECT * FROM ' . prefixTable('items') . '
        WHERE inactif=%i AND id_tree = %i',
        0,
        $completTree[$nodeId]->id
    );
    $itemsNb = DB::count();

    // If personal Folder, convert id into user name
    if ((int) $completTree[$nodeId]->title === (int) $session_user_id && (int) $completTree[$nodeId]->nlevel === 1) {
        $completTree[$nodeId]->title = $session_login;
    }

    // Decode if needed
    $completTree[$nodeId]->title = htmlspecialchars_decode($completTree[$nodeId]->title, ENT_QUOTES);

    $nodeData = prepareNodeData(
        $completTree,
        (int) $completTree[$nodeId]->id,
        $session_groupes_visibles,
        $session_read_only_folders,
        $session_personal_visible_groups,
        (int) $nbChildrenItems,
        $nodeDescendants,
        (int) $itemsNb,
        $session_list_folders_limited,
        (int) $SETTINGS['show_only_accessible_folders'],
        $nodeDirectDescendants,
        isset($SETTINGS['tree_counters']) === true ? (int) $SETTINGS['tree_counters'] : '',
        (int) $session_user_read_only,
        $listFoldersLimitedKeys,
        $listRestrictedFoldersForItemsKeys,
        $session_list_restricted_folders_for_items,
        $session_personal_folder
    );

    // prepare json return for current node
    $parent = $completTree[$nodeId]->parent_id === '0' ? '#' : 'li_' . $completTree[$nodeId]->parent_id;

    // handle displaying
    if (
        isset($SETTINGS['show_only_accessible_folders']) === true
        && (int) $SETTINGS['show_only_accessible_folders'] === 1
    ) {
        if ($nodeData['hide_node'] === true) {
            $last_visible_parent = (int) $parent;
            $last_visible_parent_level = $completTree[$nodeId]->nlevel--;
        } elseif ($completTree[$nodeId]->nlevel < $last_visible_parent_level) {
            $last_visible_parent = -1;
        }
    }

    // json
    if ($nodeData['hide_node'] === false && $nodeData['show_but_block'] === false) {
        array_push(
            $ret_json,
            array(
                'id' => 'li_' . $completTree[$nodeId]->id,
                'parent' => $last_visible_parent === -1 ? $parent : $last_visible_parent,
                'text' => '<i class="'.$completTree[$nodeId]->fa_icon.' tree-folder mr-2" data-folder="'.$completTree[$nodeId]->fa_icon.'"  data-folder-selected="'.$completTree[$nodeId]->fa_icon_selected.'"></i>'.$text.$completTree[$nodeId]->title.$nodeData['html'],
                'li_attr' => array(
                    'class' => 'jstreeopen',
                    'title' => 'ID [' . $completTree[$nodeId]->id . '] ' . $nodeData['title'],
                ),
                'a_attr' => array(
                    'id' => 'fld_' . $completTree[$nodeId]->id,
                    'class' => $nodeData['folderClass'],
                    'onclick' => 'ListerItems(' . $completTree[$nodeId]->id . ', ' . $nodeData['restricted'] . ', 0, 1)',
                    'data-title' => $completTree[$nodeId]->title,
                ),
                'is_pf' => in_array($completTree[$nodeId]->id, $session_personal_folder) === true ? 1 : 0,
                'can_edit' => (int) $can_create_root_folder,
            )
        );
    } elseif ($nodeData['show_but_block'] === true) {
        array_push(
            $ret_json,
            array(
                'id' => 'li_' . $completTree[$nodeId]->id,
                'parent' => $last_visible_parent === -1 ? $parent : $last_visible_parent,
                'text' => '<i class="'.$completTree[$nodeId]->fa_icon.' tree-folder mr-2" data-folder="'.$completTree[$nodeId]->fa_icon.'"  data-folder-selected="'.$completTree[$nodeId]->fa_icon_selected.'"></i>'.'<i class="fas fa-times fa-xs text-danger mr-1 ml-1"></i>'.$text.$completTree[$nodeId]->title.$nodeData['html'],
                'li_attr' => array(
                    'class' => '',
                    'title' => 'ID [' . $completTree[$nodeId]->id . '] ' . langHdl('no_access'),
                ),
            )
        );
    }
    
    foreach ($completTree[$nodeId]->children as $child) {
        recursiveTree(
            $child,
            $completTree,
            /** @scrutinizer ignore-type */ $tree,
            $listFoldersLimitedKeys,
            $listRestrictedFoldersForItemsKeys,
            $last_visible_parent,
            $last_visible_parent_level,
            $SETTINGS,
            $session_forbiden_pfs,
            $session_groupes_visibles,
            $session_list_restricted_folders_for_items,
            $session_user_id,
            $session_login,
            $session_user_read_only,
            $session_personal_folder,
            $session_list_folders_limited,
            $session_read_only_folders,
            $session_personal_visible_groups,
            $can_create_root_folder,
            $ret_json
        );
    }
}



function prepareNodeData(
    $completTree,
    $nodeId,
    $session_groupes_visibles,
    $session_read_only_folders,
    $session_personal_visible_groups,
    $nbChildrenItems,
    $nodeDescendants,
    $itemsNb,
    $session_list_folders_limited,
    $show_only_accessible_folders,
    $nodeDirectDescendants,
    $tree_counters,
    $session_user_read_only,
    $listFoldersLimitedKeys,
    $listRestrictedFoldersForItemsKeys,
    $session_list_restricted_folders_for_items,
    $session_personal_folder
): array
{
    // special case for READ-ONLY folder
    $title = '';
    if (
        $session_user_read_only === true
        && in_array($completTree[$nodeId]->id, $session_user_read_only) === false
    ) {
        $title = langHdl('read_only_account');
    }

    if (in_array($nodeId, $session_groupes_visibles) === true) {
        if (in_array($nodeId, $session_read_only_folders) === true) {
            return [
                'html' => '<i class="far fa-eye fa-xs mr-1 ml-1"></i><span class="badge badge-pill badge-light ml-2 items_count" id="itcount_' . $nodeId . '">' . $itemsNb .
                    ($tree_counters === 1 ? '/'.$nbChildrenItems .'/'.(count($nodeDescendants) - 1)  : '') . '</span>',
                'title' => langHdl('read_only_account'),
                'restricted' => 1,
                'folderClass' => 'folder_not_droppable',
                'show_but_block' => false,
                'hide_node' => false,
                'is_pf' => in_array($nodeId, $session_personal_folder) === true ? 1 : 0,
            ];
        }

        elseif (
            $session_user_read_only === true
            && in_array($nodeId, $session_personal_visible_groups) === false
        ) {
            return [
                'html' => '<i class="far fa-eye fa-xs mr-1"></i><span class="badge badge-pill badge-light ml-2 items_count" id="itcount_' . $nodeId . '">' . $itemsNb .
                    ($tree_counters === 1 ? '/'.$nbChildrenItems .'/'.(count($nodeDescendants) - 1)  : '') . '</span>',
                'title' => $title,
                'restricted' => 0,
                'folderClass' => 'folder',
                'show_but_block' => false,
                'hide_node' => false,
                'is_pf' => in_array($nodeId, $session_personal_folder) === true ? 1 : 0,
            ];
        }
        
        return [
            'html' => '<span class="badge badge-pill badge-light ml-2 items_count" id="itcount_' . $nodeId . '">' . $itemsNb .
                ($tree_counters === 1 ? '/'.$nbChildrenItems .'/'.(count($nodeDescendants) - 1)  : '') . '</span>',
            'title' => $title,
            'restricted' => 0,
            'folderClass' => 'folder',
            'show_but_block' => false,
            'hide_node' => false,
            'is_pf' => in_array($nodeId, $session_personal_folder) === true ? 1 : 0,
        ];
    }
    
    elseif (in_array($nodeId, $listFoldersLimitedKeys) === true) {
        return [
            'html' => ($session_user_read_only === true ? '<i class="far fa-eye fa-xs mr-1"></i>' : '') .
                '<span class="badge badge-pill badge-light ml-2 items_count" id="itcount_' . $nodeId . '">' . count($session_list_folders_limited[$nodeId]) . '</span>',
            'title' => $title,
            'restricted' => 1,
            'folderClass' => 'folder',
            'show_but_block' => false,
            'hide_node' => false,
            'is_pf' => in_array($nodeId, $session_personal_folder) === true ? 1 : 0,
        ];
    }
    
    elseif (in_array($nodeId, $listRestrictedFoldersForItemsKeys) === true) {
        return [
            'html' => $session_user_read_only === true ? '<i class="far fa-eye fa-xs mr-1"></i>' : '' .
                '<span class="badge badge-pill badge-light ml-2 items_count" id="itcount_' . $nodeId . '">' . count($session_list_restricted_folders_for_items[$nodeId]) . '</span>',
            'title' => $title,
            'restricted' => 1,
            'folderClass' => 'folder',
            'show_but_block' => false,
            'hide_node' => false,
            'is_pf' => in_array($nodeId, $session_personal_folder) === true ? 1 : 0,
        ];
    }
    
    elseif ((int) $show_only_accessible_folders === 1
        && (int) $nbChildrenItems === 0
    ) {
        // folder should not be visible
        // only if it has no descendants
        if (
            count(
                array_diff(
                    $nodeDirectDescendants,
                    array_merge(
                        $session_groupes_visibles,
                        array_keys($session_list_restricted_folders_for_items)
                    )
                )
            ) !== count($nodeDirectDescendants)
        ) {
            // show it but block it
            return [
                'html' => '',
                'title' => $title,
                'restricted' => 1,
                'folderClass' => 'folder_not_droppable',
                'show_but_block' => true,
                'hide_node' => false,
                'is_pf' => in_array($nodeId, $session_personal_folder) === true ? 1 : 0,
            ];
        }
        
        // hide it
        return [
            'html' => '',
            'title' => $title,
            'restricted' => 1,
            'folderClass' => 'folder_not_droppable',
            'show_but_block' => false,
            'hide_node' => true,
            'is_pf' => in_array($nodeId, $session_personal_folder) === true ? 1 : 0,
        ];
    }

    return [
        'html' => '',
        'title' => isset($title) === true ? $title : '',
        'restricted' => 1,
        'folderClass' => 'folder_not_droppable',
        'show_but_block' => true,
        'hide_node' => false,
        'is_pf' => in_array($nodeId, $session_personal_folder) === true ? 1 : 0,
    ];
}
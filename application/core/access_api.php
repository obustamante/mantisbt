<?php
# MantisBT - A PHP based bugtracking system

# MantisBT is free software: you can redistribute it and/or modify
# it under the terms of the GNU General Public License as published by
# the Free Software Foundation, either version 2 of the License, or
# (at your option) any later version.
#
# MantisBT is distributed in the hope that it will be useful,
# but WITHOUT ANY WARRANTY; without even the implied warranty of
# MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
# GNU General Public License for more details.
#
# You should have received a copy of the GNU General Public License
# along with MantisBT.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Access API
 *
 * @package CoreAPI
 * @subpackage AccessAPI
 * @copyright Copyright (C) 2000 - 2002  Kenzaburo Ito - kenito@300baud.org
 * @copyright Copyright (C) 2002 - 2012  MantisBT Team - mantisbt-dev@lists.sourceforge.net
 * @link http://www.mantisbt.org
 *
 * @uses authentication_api.php
 * @uses bug_api.php
 * @uses bugnote_api.php
 * @uses config_api.php
 * @uses constant_inc.php
 * @uses current_user_api.php
 * @uses database_api.php
 * @uses error_api.php
 * @uses helper_api.php
 * @uses lang_api.php
 * @uses print_api.php
 * @uses project_api.php
 * @uses string_api.php
 * @uses user_api.php
 */

use MantisBT\Exception\Access\AccessDenied;

require_api( 'authentication_api.php' );
require_api( 'bug_api.php' );
require_api( 'bugnote_api.php' );
require_api( 'config_api.php' );
require_api( 'constant_inc.php' );
require_api( 'current_user_api.php' );
require_api( 'database_api.php' );
require_api( 'error_api.php' );
require_api( 'helper_api.php' );
require_api( 'lang_api.php' );
require_api( 'print_api.php' );
require_api( 'project_api.php' );
require_api( 'string_api.php' );
require_api( 'user_api.php' );

/**
 *
 * @global array $g_cache_access_matrix
 */
$g_cache_access_matrix = array();

/**
 *
 * @global array $g_cache_access_matrix_project_ids
 */
$g_cache_access_matrix_project_ids = array();

/**
 *
 * @global array $g_cache_access_matrix_user_ids
 */
$g_cache_access_matrix_user_ids = array();

/**
 * retrieves and returns access matrix for a project from cache or caching if required.
 * @param int $p_project_id integer representing project id
 * @return  array returns an array of users->accesslevel for the given user
 * @access private
 */
function access_cache_matrix_project( $p_project_id ) {
	global $g_cache_access_matrix, $g_cache_access_matrix_project_ids;

	if( ALL_PROJECTS == (int)$p_project_id ) {
		return array();
	}

	if( !in_array( (int) $p_project_id, $g_cache_access_matrix_project_ids ) ) {
		$query = "SELECT user_id, access_level
					  FROM {project_user_list}
					  WHERE project_id=" . db_param();
		$t_result = db_query_bound( $query, array( (int)$p_project_id ) );

		while( $t_row = db_fetch_array( $t_result ) ) {
			$g_cache_access_matrix[(int) $t_row['user_id']][(int) $p_project_id] = (int) $t_row['access_level'];
		}

		$g_cache_access_matrix_project_ids[] = (int) $p_project_id;
	}

	$t_results = array();

	foreach( $g_cache_access_matrix as $t_user ) {
		if( isset( $t_user[(int) $p_project_id] ) ) {
			$t_results[(int) $p_project_id] = $t_user[(int) $p_project_id];
		}
	}

	return $t_results;
}

/**
 * retrieves and returns access matrix for a user from cache or caching if required.
 * @param int $p_user_id integer representing user id
 * @return  array returns an array of projects->accesslevel for the given user
 * @access private
 */
function access_cache_matrix_user( $p_user_id ) {
	global $g_cache_access_matrix, $g_cache_access_matrix_user_ids;

	if( !in_array( (int) $p_user_id, $g_cache_access_matrix_user_ids ) ) {
		$query = "SELECT project_id, access_level
					  FROM {project_user_list}
					  WHERE user_id=" . db_param();
		$result = db_query_bound( $query, array( (int)$p_user_id ) );

		# make sure we always have an array to return
		$g_cache_access_matrix[(int) $p_user_id] = array();

		while( $t_row = db_fetch_array( $result ) ) {
			$g_cache_access_matrix[(int) $p_user_id][(int) $t_row['project_id']] = (int) $t_row['access_level'];
		}

		$g_cache_access_matrix_user_ids[] = (int) $p_user_id;
	}

	return $g_cache_access_matrix[(int) $p_user_id];
}

/**
 * Check the a user's access against the given "threshold" and return true
 * if the user can access, false otherwise.
 * $p_access_level may be a single value, or an array. If it is a single
 * value, treat it as a threshold so return true if user is >= threshold.
 * If it is an array, look for exact matches to one of the values
 * @param int $p_user_access_level user access level
 * @param int|array $p_threshold access threshold, defaults to NOBODY
 * @return bool true or false depending on whether given access level matches the threshold
 * @access public
 */
function access_compare_level( $p_user_access_level, $p_threshold = NOBODY ) {
	if( is_array( $p_threshold ) ) {
		return( in_array( $p_user_access_level, $p_threshold ) );
	} else {
		return( $p_user_access_level >= $p_threshold );
	}
}

/**
 * This function only checks the user's global access level, ignoring any
 * overrides they might have at a project level
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @return int global access level
 * @access public
 */
function access_get_global_level( $p_user_id = null ) {
	if( $p_user_id === null ) {
		$p_user_id = auth_get_current_user_id();
	}

	# Deal with not logged in silently in this case
	# @@@ we may be able to remove this and just error
	#     and once we default to anon login, we can remove it for sure
	if( empty( $p_user_id ) && !auth_is_user_authenticated() ) {
		return false;
	}

	return user_get_field( $p_user_id, 'access_level' );
}

/**
 * Check the current user's access against the given value and return true
 * if the user's access is equal to or higher, false otherwise.
 * @param int $p_access_level integer representing access level
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @return bool whether user has access level specified
 * @access public
 */
function access_has_global_level( $p_access_level, $p_user_id = null ) {
	# Short circuit the check in this case
	if( NOBODY == $p_access_level ) {
		return false;
	}

	if( $p_user_id === null ) {
		$p_user_id = auth_get_current_user_id();
	}

	$t_access_level = access_get_global_level( $p_user_id );

	return access_compare_level( $t_access_level, $p_access_level );
}

/**
 * Check if the user has the specified global access level
 * and deny access to the page if not
 * @see access_has_global_level
 * @param int $p_access_level integer representing access level
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @access public
 */
function access_ensure_global_level( $p_access_level, $p_user_id = null ) {
	if( !access_has_global_level( $p_access_level, $p_user_id ) ) {
		throw new AccessDenied();
	}
}

/**
 * This function checks the project access level first (for the current project
 * if none is specified) and if the user is not listed, it falls back on the
 * user's global access level.
 * @param int $p_project_id integer representing project id to check access against
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @return int access level user has to given project
 * @access public
 */
function access_get_project_level( $p_project_id = null, $p_user_id = null ) {
	if( null === $p_user_id ) {
		$p_user_id = auth_get_current_user_id();
	}

	# Deal with not logged in silently in this case
	/** @todo we may be able to remove this and just error and once we default to anon login, we can remove it for sure */
	if( empty( $p_user_id ) && !auth_is_user_authenticated() ) {
		return ANYBODY;
	}

	if( null === $p_project_id ) {
		$p_project_id = helper_get_current_project();
	}

	$t_global_access_level = access_get_global_level( $p_user_id );

	if( ALL_PROJECTS == $p_project_id || user_is_administrator( $p_user_id ) ) {
		return $t_global_access_level;
	} else {
		$t_project_access_level = access_get_local_level( $p_user_id, $p_project_id );
		$t_project_view_state = project_get_field( $p_project_id, 'view_state' );

		# Try to use the project access level.
		# If the user is not listed in the project, then try to fall back
		#  to the global access level
		if( false === $t_project_access_level ) {
			# If the project is private and the user isn't listed, then they
			# must have the private_project_threshold access level to get in.
			if( VS_PRIVATE == $t_project_view_state ) {
				if( access_compare_level( $t_global_access_level, config_get( 'private_project_threshold', null, null, ALL_PROJECTS ) ) ) {
					return $t_global_access_level;
				} else {
					return ANYBODY;
				}
			} else {
				# project access not set, but the project is public
				return $t_global_access_level;
			}
		} else {
			# project specific access was set
			return $t_project_access_level;
		}
	}
}

/**
 * Check the current user's access against the given value and return true
 * if the user's access is equal to or higher, false otherwise.
 * @param int $p_access_level integer representing access level
 * @param int $p_project_id integer representing project id to check access against
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @return bool whether user has access level specified
 * @access public
 */
function access_has_project_level( $p_access_level, $p_project_id = null, $p_user_id = null ) {
	# Short circuit the check in this case
	if( NOBODY == $p_access_level ) {
		return false;
	}

	if( null === $p_user_id ) {
		$p_user_id = auth_get_current_user_id();
	}
	if( null === $p_project_id ) {
		$p_project_id = helper_get_current_project();
	}

	$t_access_level = access_get_project_level( $p_project_id, $p_user_id );

	return access_compare_level( $t_access_level, $p_access_level );
}

/**
 * Check if the user has the specified access level for the given project
 * and deny access to the page if not
 * @see access_has_project_level
 * @param int $p_access_level integer representing access level
 * @param int|null $p_project_id integer representing project id to check access against, defaults to null to use current project
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @access public
 */
function access_ensure_project_level( $p_access_level, $p_project_id = null, $p_user_id = null ) {
	if( !access_has_project_level( $p_access_level, $p_project_id, $p_user_id ) ) {
		throw new AccessDenied();
	}
}

/**
 * Check whether the user has the specified access level for any project project
 * @param int $p_access_level integer representing access level
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @return bool whether user has access level specified
 * @access public
 */
function access_has_any_project( $p_access_level, $p_user_id = null ) {
	# Short circuit the check in this case

	if( NOBODY == $p_access_level ) {
		return false;
	}

	if( null === $p_user_id ) {
		$p_user_id = auth_get_current_user_id();
	}

	$t_projects = project_get_all_rows();
	foreach( $t_projects as $t_project ) {
		if ( access_has_project_level( $p_access_level, $t_project['id'], $p_user_id ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Check the current user's access against the given value and return true
 * if the user's access is equal to or higher, false otherwise.
 * This function looks up the bug's project and performs an access check
 * against that project
 * @param int $p_access_level integer representing access level
 * @param int $p_bug_id integer representing bug id to check access against
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @return bool whether user has access level specified
 * @access public
 */
function access_has_bug_level( $p_access_level, $p_bug_id, $p_user_id = null ) {
	if( $p_user_id === null ) {
		$p_user_id = auth_get_current_user_id();
	}

	# Deal with not logged in silently in this case
	# @@@ we may be able to remove this and just error
	#     and once we default to anon login, we can remove it for sure
	if( empty( $p_user_id ) && !auth_is_user_authenticated() ) {
		return false;
	}

	$t_project_id = bug_get_field( $p_bug_id, 'project_id' );

	# check limit_Reporter (Issue #4769)
	# reporters can view just issues they reported
	$t_limit_reporters = config_get( 'limit_reporters' );
	if(( ON === $t_limit_reporters ) && ( !bug_is_user_reporter( $p_bug_id, $p_user_id ) ) && ( !access_has_project_level( REPORTER + 1, $t_project_id, $p_user_id ) ) ) {
		return false;
	}

	# If the bug is private and the user is not the reporter, then
	# they must also have higher access than private_bug_threshold
	if( VS_PRIVATE == bug_get_field( $p_bug_id, 'view_state' ) && !bug_is_user_reporter( $p_bug_id, $p_user_id ) ) {
		$t_access_level = access_get_project_level( $t_project_id, $p_user_id );
		return access_compare_level( $t_access_level, config_get( 'private_bug_threshold' ) )
		    && access_compare_level( $t_access_level, $p_access_level );
	}

	return access_has_project_level( $p_access_level, $t_project_id, $p_user_id );
}

/**
 * Check if the user has the specified access level for the given bug
 * and deny access to the page if not
 * @see access_has_bug_level
 * @param int $p_access_level integer representing access level
 * @param int $p_bug_id integer representing bug id to check access against
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @return bool whether user has access level specified
 * @access public
 */
function access_ensure_bug_level( $p_access_level, $p_bug_id, $p_user_id = null ) {
	if( !access_has_bug_level( $p_access_level, $p_bug_id, $p_user_id ) ) {
		throw new AccessDenied();
	}
}

/**
 * Check the current user's access against the given value and return true
 * if the user's access is equal to or higher, false otherwise.
 * This function looks up the bugnote's bug and performs an access check
 * against that bug
 * @param int $p_access_level integer representing access level
 * @param int $p_bugnote_id integer representing bugnote id to check access against
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @return bool whether user has access level specified
 * @access public
 */
function access_has_bugnote_level( $p_access_level, $p_bugnote_id, $p_user_id = null ) {
	if( null === $p_user_id ) {
		$p_user_id = auth_get_current_user_id();
	}

	# If the bug is private and the user is not the reporter, then the
	#  the user must also have higher access than private_bug_threshold
	if( VS_PRIVATE == bugnote_get_field( $p_bugnote_id, 'view_state' ) && !bugnote_is_user_reporter( $p_bugnote_id, $p_user_id ) ) {
		$p_access_level = max( $p_access_level, config_get( 'private_bugnote_threshold' ) );
	}

	$t_bug_id = bugnote_get_field( $p_bugnote_id, 'bug_id' );

	return access_has_bug_level( $p_access_level, $t_bug_id, $p_user_id );
}

/**
 * Check if the user has the specified access level for the given bugnote
 * and deny access to the page if not
 * @see access_has_bugnote_level
 * @param int $p_access_level integer representing access level
 * @param int $p_bugnote_id integer representing bugnote id to check access against
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @access public
 */
function access_ensure_bugnote_level( $p_access_level, $p_bugnote_id, $p_user_id = null ) {
	if( !access_has_bugnote_level( $p_access_level, $p_bugnote_id, $p_user_id ) ) {
		throw new AccessDenied();
	}
}

/**
 * Check if the specified bug can be closed
 * @param BugData $p_bug Bug to check access against
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @return bool true if user can close the bug
 * @access public
 */
function access_can_close_bug( $p_bug, $p_user_id = null ) {
	if( bug_is_closed( $p_bug->id ) ) {
		# Can't close a bug that's already closed
		return false;
	}

	if( null === $p_user_id ) {
		$p_user_id = auth_get_current_user_id();
	}

	# If allow_reporter_close is enabled, then reporters can close their own bugs
	# if they are in resolved status
	if(    ON == config_get( 'allow_reporter_close', null, null, $p_bug->project_id )
		&& bug_is_user_reporter( $p_bug->id, $p_user_id )
		&& bug_is_resolved( $p_bug->id )
	) {
		return true;
	}

	$t_closed_status = config_get( 'bug_closed_status_threshold', null, null, $p_bug->project_id );
	$t_closed_status_threshold = access_get_status_threshold( $t_closed_status, $p_bug->project_id );
	return access_has_bug_level( $t_closed_status_threshold, $p_bug->id, $p_user_id );
}

/**
 * Make sure that the user can close the specified bug
 * @see access_can_close_bug
 * @param BugData $p_bug Bug to check access against
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @access public
 */
function access_ensure_can_close_bug( $p_bug, $p_user_id = null ) {
	if( !access_can_close_bug( $p_bug, $p_user_id ) ) {
		throw new AccessDenied();
	}
}

/**
 * Check if the specified bug can be reopened
 * @param BugData $p_bug Bug to check access against
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @return bool whether user has access to reopen bugs
 * @access public
 */
function access_can_reopen_bug( $p_bug, $p_user_id = null ) {
	if( !bug_is_resolved( $p_bug->id ) ) {
		# Can't reopen a bug that's not resolved
		return false;
	}

	if( $p_user_id === null ) {
		$p_user_id = auth_get_current_user_id();
	}

	# If allow_reporter_reopen is enabled, then reporters can always reopen their own bugs
	if(    ON == config_get( 'allow_reporter_reopen', null, null, $p_bug->project_id )
		&& bug_is_user_reporter( $p_bug->id, $p_user_id )
	) {
		return true;
	}

	$t_reopen_status = config_get( 'reopen_bug_threshold', null, null, $p_bug->project_id );
	$t_reopen_status_threshold = access_get_status_threshold( $t_reopen_status, $p_bug->project_id );

	return access_has_bug_level( $t_reopen_status_threshold, $p_bug->id, $p_user_id );
}

/**
 * Make sure that the user can reopen the specified bug.
 * @see access_can_reopen_bug
 * @param BugData $p_bug Bug to check access against
 * @param int|null $p_user_id integer representing user id, defaults to null to use current user
 * @access public
 */
function access_ensure_can_reopen_bug( $p_bug, $p_user_id = null ) {
	if( !access_can_reopen_bug( $p_bug, $p_user_id ) ) {
		throw new AccessDenied();
	}
}

/**
 * get the user's access level specific to this project.
 * return false (0) if the user has no access override here
 * @param int $p_user_id Integer representing user id
 * @param int $p_project_id integer representing project id
 * @return bool|int returns false (if no access) or an integer representing level of access
 * @access public
 */
function access_get_local_level( $p_user_id, $p_project_id ) {
	global $g_cache_access_matrix, $g_cache_access_matrix_project_ids;

	$p_project_id = (int) $p_project_id;
	$p_user_id = (int) $p_user_id;

	if( in_array( $p_project_id, $g_cache_access_matrix_project_ids ) ) {
		if( isset( $g_cache_access_matrix[$p_user_id][$p_project_id] ) ) {
			return $g_cache_access_matrix[$p_user_id][$p_project_id];
		} else {
			return false;
		}
	}

	$t_project_level = access_cache_matrix_user( $p_user_id );

	if( isset( $t_project_level[$p_project_id] ) ) {
		return $t_project_level[$p_project_id];
	} else {
		return false;
	}
}

/**
 * get the access level required to change the issue to the new status
 * If there is no specific differentiated access level, use the
 * generic update_bug_status_threshold.
 * @param int $p_status
 * @param int $p_project_id Default value ALL_PROJECTS
 * @return int integer representing user level e.g. DEVELOPER
 * @access public
 */
function access_get_status_threshold( $p_status, $p_project_id = ALL_PROJECTS ) {
	$t_thresh_array = config_get( 'set_status_threshold', null, null, $p_project_id );
	if( isset( $t_thresh_array[(int)$p_status] ) ) {
		return (int)$t_thresh_array[(int)$p_status];
	} else {
		return config_get( 'update_bug_status_threshold', null, null, $p_project_id );
	}
}
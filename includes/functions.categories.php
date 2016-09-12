<?php
/**
 * Contains all the functions related to categories
 *
 * @package		ProjectSend
 * 
 */

function get_categories( $params = array() ) {
	global $dbh;
	$sql_params = array();

	// Set some defaults
	$orderby	= ( !empty( $params['orderby'] ) ) ? $params['orderby'] : 'name';
	$order		= ( !empty( $params['order'] ) ) ? $params['order'] : 'ASC';
	$parent		= ( !empty( $params['parent'] ) ) ? $params['parent'] : false;
	$id			= ( !empty( $params['id'] ) ) ? $params['id'] : false;

	/**
	 * By default, count files assigned to each category.
	 * Avoids doing this individually later if needed.
	 */
	$files_count = array();
	$statement = $dbh->prepare("SELECT cat_id, COUNT(file_id) as count FROM " . TABLE_CATEGORIES_RELATIONS . " GROUP BY cat_id");
	$statement->execute();
	$statement->setFetchMode(PDO::FETCH_ASSOC);
	while ( $row = $statement->fetch() ) {
		$files_count[$row["cat_id"]] = $row["count"];
	}

	/**
	 * Parameter ID can be an array or a single category ID
	 */
	if ( $id ) {
		if ( is_array( $id ) ) {
			$set_id = implode( ',', array_map( 'intval', array_unique( $id ) ) );
			$conditions[]			= 'FIND_IN_SET(id, :categories)';
			$sql_params[':categories']	= $set_id;
		}
		else {
			$conditions[]		= 'ID = :id';
			$sql_params[':id']	= $id;
		}
	}
	
	$return		= array(
						'count'				=> 0,
						'no_results_type'	=> '',
						'categories'		=> array(),
					);

	/** Begin construction of the SQL sentence */
	$sql = "SELECT * FROM " . TABLE_CATEGORIES;
	
	/** Add the search terms */	
	if ( isset( $params['search'] ) && !empty( $params['search'] ) ) {
		$conditions[]				= "(name LIKE :name)";
		$return['no_results_type']	= 'search';
		$search_terms				= '%'.$params['search'].'%';
		$sql_params[':name']		= $search_terms;
	}
	
	/**
		Clients can only manage their own categories
		TODO: Implement this
	*/	
	if (CURRENT_USER_LEVEL == '0') {
		$conditions[] = "created_by = :username";
		$sql_params[':username'] = CURRENT_USER_USERNAME;
	}

	/**
	 * Apply the conditions to the SQL sentence
	 */
	if ( !empty( $conditions ) ) {
		foreach ( $conditions as $index => $condition ) {
			$sql .= ( $index == 0 ) ? ' WHERE ' : ' AND ';
			$sql .= $condition;
		}
	}

	$sql .= " ORDER BY $orderby $order";

	$statement = $dbh->prepare( $sql );
	$statement->execute( $sql_params );

	/** Count results and add the value to the response array */
	$count				= $statement->rowCount();
	$return['count']	= $count;

	if ( $count > 0 ) {
		$statement->setFetchMode(PDO::FETCH_ASSOC);
		
		/**
		 * Fetch all initially to only do it once.
		 */
		$rows = $statement->fetchAll();
		$found_categories = array();
		foreach ($rows as $row) {
			$file_count = ( !empty( $files_count ) && array_key_exists( $row['id'], $files_count ) ) ? $files_count[$row['id']] : 0;

			$found_categories[$row['id']] = array(
												'id'			=> $row['id'],
												'name'			=> $row['name'],
												'parent'		=> (empty( $row['parent'] ) ) ? 0 : $row['parent'],
												'description'	=> $row['description'],
												'created_by'	=> $row['created_by'],
												'timestamp'		=> $row['timestamp'],
												'depth'			=> 0,
												'file_count'	=> $file_count,
												'children'		=> null,
											);
		}
		
		$return['arranged'] = arrange_categories( $found_categories );

		$return['categories'] = $found_categories;
	}

	return $return;	
	print_r($return);
}

/**
 * Arrange is an external function so it can be called from anywhere.
 * Returns an array of categories nested by parent
 */
function arrange_categories(array &$elements, $parent = 0, $depth = 0) {
    $branch = array();
    foreach ($elements as $element) {
        if ($element['parent'] == $parent) {
			$element['depth'] = $depth++;
            $children = arrange_categories($elements, $element['id'], $depth);

            if ($children) {
                $element['children'] = $children;
            }

            $branch[$element['id']] = $element;
			$element['depth'] = $depth--;
        }
    }
    return $branch;
}


function generate_categories_options( $categories, $parent = 0, $selected = array(), $ignore = 0 ) {
	$return = '';

	if ( !empty( $categories ) ) {
		foreach ( $categories as $category ) {
			$depth = ( $category['depth'] > 0 ) ? str_repeat( '--', $category['depth'] ) : false;

			$is_selected = ( in_array( $category['id'], $selected ) ) ? " selected='selected'" : '';

			if ( $category['id'] != $ignore ) {
				$format = "<option value='%s'%s>%s%s</option>\n";
				$return .= sprintf( $format, $category['id'], $is_selected, $depth, html_output($category['name']) );
			}

			$children = $category['children'];
			if ( !empty( $children ) ) {
				$return .= generate_categories_options( $children, $category['parent'], $selected, $ignore );
			}
		}
	}
	
	return $return;
}
?>
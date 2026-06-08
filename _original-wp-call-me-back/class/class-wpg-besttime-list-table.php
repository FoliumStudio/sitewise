<?php

if (!class_exists('WP_List_Table_Copy')) {
    require_once(plugin_dir_path(__FILE__) . 'class-wp-list-table-copy.php');
}

class Wpg_Besttime_List_Table extends WP_List_Table_Copy {

    var $example_data = array();

    function __construct() {
        global $status, $page;
        global $wpdb;
        $Lists = $wpdb->get_results('SELECT * FROM  ' . $wpdb->prefix . 'call_back_best_time');
        //echo $wpdb->last_query;
        $i = 0;
        foreach ($Lists as $List) {
            $this->example_data[$i]['id'] = $List->id;
            $this->example_data[$i]['best_time'] = stripslashes($List->best_time);           
            if ($_GET['paged'] != '') {
                $actions = array(
                    'edit' => sprintf('<a href="?page=%s&action=%s&best_time=%s&paged=%s">Edit</a>', 'rcb-best-time', 'edit', $List->id, $_GET['paged']),
                    'delete' => sprintf('<a href="?page=%s&action=%s&best_time=%s&paged=%s" style="color:red">Delete</a>', 'delete-best-time', 'delete', $List->id, $_GET['paged']),
                );
            } else {
                $actions = array(
                    'edit' => sprintf('<a href="?page=%s&action=%s&best_time=%s">Edit</a>', 'rcb-best-time', 'edit', $List->id),
                    'delete' => sprintf('<a href="?page=%s&action=%s&best_time=%s" style="color:red">Delete</a>', 'delete-best-time', 'delete', $List->id),
                );
            }

            $actions = sprintf('%1$s %2$s', $item['best_time'], $this->row_actions($actions));
            $this->example_data[$i]['Action'] = $actions;
            
            $i++;
        }
        //echo '<pre>'; print_r($this->example_data);
        parent::__construct(array(
            'singular' => __('best_time', 'wpgcallbacklist'), //singular name of the listed records
            'plural' => __('best_times', 'wpgcallbacklist'), //plural name of the listed records
            'ajax' => false        //does this table support ajax?
        ));

        add_action('admin_head', array(&$this, 'admin_header'));
    }

    function admin_header() {
        $page = ( isset($_GET['page']) ) ? esc_attr($_GET['page']) : false;
        if ('my_list_test' != $page)
            return;
        echo '<style type="text/css">';
        echo '.wp-list-table .column-id { width: 5%; }';
        echo '.wp-list-table .column-booktitle { width: 40%; }';
        echo '.wp-list-table .column-author { width: 35%; }';
        echo '.wp-list-table .column-isbn { width: 20%;}';
        echo '</style>';
    }

    function no_items() {
        _e('No Best Time found, dude.');
    }

    function column_default($item, $column_name) {
        switch ($column_name) {
            case 'best_time':            
            case 'Action':
                return $item[$column_name];
            default:
                return print_r($item, true); //Show the whole array for troubleshooting purposes
        }
    }

    function get_sortable_columns() {
        $sortable_columns = array(
            'best_time' => array('best_time', true),            
        );
        return $sortable_columns;
    }

    function get_columns() {
        $columns = array(
            'cb' => '<input type="checkbox" />',
            'best_time' => __('Best Time', 'wpgcallbacklist'),    
            'Action' => __('Action', 'wpgcallbacklist'),
        );
        return $columns;
    }

    function usort_reorder($a, $b) {
        // If no sort, default to title
        $orderby = (!empty($_GET['orderby']) ) ? $_GET['orderby'] : 'best_time';
        // If no order, default to asc
        $order = (!empty($_GET['order']) ) ? $_GET['order'] : 'asc';
        // Determine sort order
        $result = strcmp($a[$orderby], $b[$orderby]);
        // Send final sort direction to usort
        return ( $order === 'asc' ) ? $result : -$result;
    }

    /* function column_layoutname($item) {
      $actions = array(
      'edit' => sprintf('<a href="?page=%s&action=%s&layout=%s">Edit</a>', 'create-template-layout', 'edit', $item['ID']),
      'delete' => sprintf('<a href="?page=%s&action=%s&layout=%s">Delete</a>', 'delete-template-layout', 'delete', $item['ID']),
      );

      return sprintf('%1$s %2$s', $item['layoutname'], $this->row_actions($actions));
      }*/

      function get_bulk_actions() {
      $actions = array(
      'delete' => 'Delete'
      );
      return $actions;
      }

      function process_bulk_action() {

      //Detect when a bulk action is being triggered...
      if ('delete' === $this->current_action()) {
      wp_die('Items deleted (or they would be if we had items to delete)!');
      }
      } 

    function column_cb($item) {
        return sprintf(
                 '<input type="checkbox" name="%1$s[]" value="%2$s" />',
                /* $1%s */ $this->_args['singular'], //Let's simply repurpose the table's singular label ("movie")
                /* $2%s */ $item['id']                //The value of the checkbox should be the record's id
        );
    }

    function prepare_items() {
        $columns = $this->get_columns();
        $hidden = array();
        $sortable = $this->get_sortable_columns();
        $this->_column_headers = array($columns, $hidden, $sortable);
        usort($this->example_data, array(&$this, 'usort_reorder'));

        $per_page = 10;
        $current_page = $this->get_pagenum();
        $total_items = count($this->example_data);

        // only ncessary because we have sample data
        $this->found_data = array_slice($this->example_data, ( ( $current_page - 1 ) * $per_page), $per_page);

        $this->set_pagination_args(array(
            'total_items' => $total_items, //WE have to calculate the total number of items
            'per_page' => $per_page                     //WE have to determine how many items to show on a page
        ));
        $this->items = $this->found_data;
    }

}

//class
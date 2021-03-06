<?php

if ( ! defined('EXT')) exit('Invalid file request');


/**
 * FF Matrix Class
 *
 * @package   FieldFrame
 * @author    Brandon Kelly <me@brandon-kelly.com>
 * @copyright Copyright (c) 2009 Brandon Kelly
 * @license   http://creativecommons.org/licenses/by-sa/3.0/ Attribution-Share Alike 3.0 Unported
 */
class Ff_matrix extends Fieldframe_Fieldtype {

	var $info = array(
		'name'     => 'FF Matrix',
		'version'  => FF_VERSION,
		'desc'     => 'Provides a tabular data fieldtype',
		'docs_url' => 'http://wiki.github.com/brandonkelly/bk.fieldframe.ee_addon/ff-matrix'
	);

	var $default_field_settings = array(
		'cols' => array(
			'1' => array('name' => 'cell_1', 'label' => 'Cell 1', 'type' => 'ff_matrix_text', 'new' => 'y'),
			'2' => array('name' => 'cell_2', 'label' => 'Cell 2', 'type' => 'ff_matrix_textarea', 'new' => 'y')
		)
	);

	var $default_tag_params = array(
		'cellspacing' => '1',
		'cellpadding' => '10',
		'limit' => '0',
		'sort' => 'asc',
		'backspace' => '0'
	);

	var $postpone_saves = TRUE;

	/**
	 * FF Matrix class constructor
	 */
	function __construct()
	{
		global $FFM;
		$FFM = $this;
	}

	/**
	 * Display Site Settings
	 */
	function display_site_settings()
	{
		global $DB, $PREFS, $DSP;

		$fields_q = $DB->query('SELECT f.field_id, f.field_label, g.group_name
		                          FROM exp_weblog_fields AS f, exp_field_groups AS g
		                          WHERE f.site_id = '.$PREFS->ini('site_id').'
		                            AND f.field_type = "data_matrix"
		                            AND f.group_id = g.group_id
		                          ORDER BY g.group_name, f.field_order, f.field_label');
		if ($fields_q->num_rows)
		{
			$SD = new Fieldframe_SettingsDisplay();

			$r = $SD->block();

			$convert_r = '';
			$last_group_name = '';
			foreach($fields_q->result as $row)
			{
				if ($row['group_name'] != $last_group_name)
				{
					$convert_r .= $DSP->qdiv('defaultBold', $row['group_name']);
					$last_group_name = $row['group_name'];
				}
				$convert_r .= '<label>'
				            . $DSP->input_checkbox('convert[]', $row['field_id'])
				            . $row['field_label']
				            . '</label>'
				            . '<br>';
			}
			$r .= $SD->row(array(
				$SD->label('convert_label', 'convert_desc'),
				$convert_r
			));

			$r .= $SD->block_c();
			return $r;
		}

		return FALSE;
	}

	/**
	 * Save Site Settings
	 *
	 * @param  array  $site_settings  The site settings post data
	 * @return array  The modified $site_settings
	 */
	function save_site_settings($site_settings)
	{
		global $DB, $FF, $LANG, $REGX;

		if (isset($site_settings['convert']))
		{
			$setting_name_maps = array(
				'short_name' => 'name',
				'title'      => 'label'
			);
			$cell_type_maps = array(
				'text'     => 'ff_matrix_text',
				'textarea' => 'ff_matrix_textarea',
				'select'   => 'ff_matrix_select',
				'date'     => 'ff_matrix_date',
				'checkbox' => 'ff_checkbox'
			);

			$fields_q = $DB->query('SELECT * FROM exp_weblog_fields
			                          WHERE field_id IN ('.implode(',', $site_settings['convert']).')');

			$sql = array();

			foreach($fields_q->result as $field)
			{
				$field_data = array('field_type' => 'ftype_id_'.$this->_fieldtype_id);

				// get the conf string
				if (($old_conf = @unserialize($field['lg_field_conf'])) !== FALSE)
				{
					$conf = (is_array($old_conf) AND isset($old_conf['string']))
					  ?  $old_conf['string']  :  '';
				}
				else
				{
					$conf = $field['lg_field_conf'];
				}

				// parse the conf string

				$field_settings = array('cols' => array());
				$col_maps = array();
				foreach(preg_split('/[\r\n]{2,}/', trim($conf)) as $col_id => $col)
				{
					// default col settings
					$col_settings = array(
						'name'  => $LANG->line('cell').' '.($col_id+1),
						'label' => strtolower($LANG->line('cell')).'_'.($col_id+1),
						'type'  => 'text'
					);

					foreach (preg_split('/[\r\n]/', $col) as $line)
					{
						$parts = explode('=', $line);
						$setting_name = trim($parts[0]);
						$setting_value = trim($parts[1]);

						if (isset($setting_name_maps[$setting_name]))
						{
							$col_settings[$setting_name_maps[$setting_name]] = $setting_value;
						}
						else if ($setting_name == 'type')
						{
							$col_settings['type'] = isset($cell_type_maps[$setting_value])
							  ?  $cell_type_maps[$setting_value]
							  :  'ff_matrix_text';
						}
					}
					$col_maps[$col_settings['name']] = $col_id;

					$field_settings['cols'][$col_id] = $col_settings;
				}

				$field_data['ff_settings'] = addslashes(serialize($field_settings));
				$field_data['lg_field_conf'] = '';
				$sql[] = $DB->update_string('exp_weblog_fields', $field_data, 'field_id = '.$field['field_id']);

				// update the weblog data

				$data_q = $DB->query('SELECT entry_id, field_id_'.$field['field_id'].' data
				                        FROM exp_weblog_data
				                        WHERE field_id_'.$field['field_id'].' != ""');

				foreach($data_q->result as $entry)
				{
					$entry_rows = array();

					if (($data = @unserialize($entry['data'])) !== FALSE)
					{
						foreach($REGX->array_stripslashes($data) as $row_count => $row)
						{
							$entry_row = array();
							$include_row = FALSE;
							foreach($row as $name => $val)
							{
								if (isset($col_maps[$name]))
								{
									$entry_row[$col_maps[$name]] = $val;
									if ( ! $include_row AND $val) $include_row = TRUE;
								}
							}
							if ($include_row) $entry_rows[] = $entry_row;
						}
					}

					$entry_data = array('field_id_'.$field['field_id'].'' => addslashes(serialize($entry_rows)));
					$sql[] = $DB->update_string('exp_weblog_data', $entry_data, 'entry_id = '.$entry['entry_id']);
				}
			}

			foreach($sql as $query)
			{
				$DB->query($query);
			}
		}
	}

	/**
	 * Get Fieldtypes
	 *
	 * @access private
	 */
	function _get_ftypes()
	{
		global $FF;

		if ( ! isset($this->ftypes))
		{
			// Add the included celltypes
			$this->ftypes = array(
				'ff_matrix_text' => new Ff_matrix_text(),
				'ff_matrix_textarea' => new Ff_matrix_textarea(),
				'ff_matrix_select' => new Ff_matrix_select(),
				'ff_matrix_date' => new Ff_matrix_date()
			);

			// Get the FF fieldtyes with display_cell
			$ftypes = array();
			foreach($FF->_get_ftypes() as $class_name => $ftype)
			{
				if (method_exists($ftype, 'display_cell'))
				{
					$ftypes[$class_name] = $ftype;
				}
			}
			$FF->_sort_ftypes($ftypes);

			// Combine with the included celltypes
			$this->ftypes = array_merge($this->ftypes, $ftypes);
		}

		return $this->ftypes;
	}

	/**
	 * Display Field Settings
	 * 
	 * @param  array  $field_settings  The field's settings
	 * @return array  Settings HTML (cell1, cell2, rows)
	 */
	function display_field_settings($field_settings)
	{
		global $DSP, $LANG;

		$this->include_css('styles/ff_matrix.css');
		$this->include_js('scripts/jquery.sorttable.js');
		$this->include_js('scripts/jquery.ff_matrix_conf.js');

		$ftypes = $this->_get_ftypes();

		$cell_types = array();
		foreach($ftypes as $class_name => $ftype)
		{
			$cell_settings = isset($ftype->default_cell_settings) ? $ftype->default_cell_settings : array();

			if (method_exists($ftype, 'display_cell_settings'))
			{
				if ( ! $ftype->info['no_lang']) $LANG->fetch_language_file($class_name);
				$settings_display = $ftype->display_cell_settings($cell_settings);
			}
			else
			{
				$settings_display = '';
			}

			$cell_types[$class_name] = array(
				'name' => $ftype->info['name'],
				'preview' => $ftype->display_cell('', '', $cell_settings),
				'settings' => $settings_display
			);
		}

		$cols = array();
		foreach($field_settings['cols'] as $col_id => $col)
		{
			// Get the fieldtype. If it doesn't exist, use a textarea in an attempt to preserve the data
			$ftype = isset($ftypes[$col['type']]) ? $ftypes[$col['type']] : $ftypes['ff_matrix_textarea'];

			$cell_settings = array_merge(
				(isset($ftype->default_cell_settings) ? $ftype->default_cell_settings : array()),
				(isset($col['settings']) ? $col['settings'] : array())
			);

			$cols[$col_id] = array(
				'name' => $col['name'],
				'label' => $col['label'],
				'type' => $col['type'],
				'preview' => $ftype->display_cell('', '', $cell_settings),
				'settings' => (method_exists($ftype, 'display_cell_settings') ? $ftype->display_cell_settings($cell_settings) : ''),
				'isNew' => isset($col['new'])
			);
		}

		// add json lib if < PHP 5.2
		include_once 'includes/jsonwrapper/jsonwrapper.php';

		$js = 'jQuery(window).bind("load", function() {' . NL
		    . '  jQuery.fn.ffMatrixConf.lang.colName = "'.$LANG->line('col_name').'";' . NL
		    . '  jQuery.fn.ffMatrixConf.lang.colLabel = "'.$LANG->line('col_label').'";' . NL
		    . '  jQuery.fn.ffMatrixConf.lang.cellType = "'.$LANG->line('cell_type').'";' . NL
		    . '  jQuery.fn.ffMatrixConf.lang.cell = "'.$LANG->line('cell').'";' . NL
		    . '  jQuery.fn.ffMatrixConf.lang.deleteColumn = "'.$LANG->line('delete_column').'";' . NL
		    . '  jQuery.fn.ffMatrixConf.lang.confirmDeleteColumn = "'.$LANG->line('confirm_delete_column').'";' . NL
		    . NL
		    . '  jQuery.fn.ffMatrixConf.cellTypes = '.json_encode($cell_types).';' . NL
		    . NL
		    . '  jQuery(".ff_matrix_conf").ffMatrixConf('.$this->_fieldtype_id.', '.json_encode($cols).');' . NL
		    . '});';

		$this->insert_js($js);

		// display the config skeleton
		$preview = $DSP->qdiv('defaultBold', $LANG->line('conf_label'))
                 . $DSP->qdiv('itemWrapper', $LANG->line('conf_subtext'))
		         . $DSP->div('ff_matrix ff_matrix_conf')
		         .   '<a class="button add" title="'.$LANG->line('add_column').'"></a>'
		         .   '<table cellspacing="0" cellpadding="0">'
		         .     '<tr class="tableHeading"></tr>'
		         .     '<tr class="preview"></tr>'
		         .     '<tr class="conf col"></tr>'
		         .     '<tr class="conf celltype"></tr>'
		         .     '<tr class="conf cellsettings"></tr>'
		         .     '<tr class="delete"></tr>'
		         .   '</table>'
		         . $DSP->div_c();

		return array('rows' => array(array($preview)));
	}

	/**
	 * Save Field Settings
	 *
	 * Turn the options textarea value into an array of option names and labels
	 * 
	 * @param  array  $settings  The user-submitted settings, pulled from $_POST
	 * @return array  Modified $settings
	 */
	function save_field_settings($field_settings)
	{
		$ftypes = $this->_get_ftypes();

		foreach($field_settings['cols'] as $col_id => &$col)
		{
			$ftype = $ftypes[$col['type']];
			if (method_exists($ftype, 'save_cell_settings'))
			{
				$col['settings'] = $ftype->save_cell_settings($col['settings']);
			}
		}

		return $field_settings;
	}

	/**
	 * Display Field
	 * 
	 * @param  string  $field_name      The field's name
	 * @param  mixed   $field_data      The field's current value
	 * @param  array   $field_settings  The field's settings
	 * @return string  The field's HTML
	 */
	function display_field($field_name, $field_data, $field_settings)
	{
		global $DSP, $REGX, $FF, $LANG;

		$ftypes = $this->_get_ftypes();

		$this->include_css('styles/ff_matrix.css');
		$this->include_js('scripts/jquery.ff_matrix.js');

		$cell_defaults = array();
		$r = '<div class="ff_matrix" id="'.$field_name.'">'
		   .   '<table cellspacing="0" cellpadding="0">'
		   .     '<tr class="tableHeading">';
		foreach($field_settings['cols'] as $col_id => $col)
		{
			// add the header
			$r .=  '<th>'.$col['label'].'</th>';

			// get the default state
			if ( ! isset($ftypes[$col['type']]))
			{
				$col['type'] = 'ff_matrix_textarea';
				$col['settings'] = array('rows' => 1);
			}
			$ftype = $ftypes[$col['type']];
			$cell_settings = array_merge(
				(isset($ftype->default_cell_settings) ? $ftype->default_cell_settings : array()),
				(isset($col['settings']) ? $col['settings'] : array())
			);
			$cell_defaults[] = array(
				'type' => $col['type'],
				'cell' => $ftype->display_cell($field_name.'[0]['.$col_id.']', '', $cell_settings)
			);
		}
		$r .=    '</tr>';

		if ( ! $field_data)
		{
			$field_data = array(array());
		}

		$num_cols = count($field_settings['cols']);
		foreach($field_data as $row_count => $row)
		{
			$r .= '<tr>';
			$col_count = 0;
			foreach($field_settings['cols'] as $col_id => $col)
			{
				if ( ! isset($ftypes[$col['type']]))
				{
					$col['type'] = 'ff_matrix_textarea';
					$col['settings'] = array('rows' => 1);
					if (isset($row[$col_id]) AND is_array($row[$col_id]))
					{
						$row[$col_id] = serialize($row[$col_id]);
					}
				}
				$ftype = $ftypes[$col['type']];
				$cell_name = $field_name.'['.$row_count.']['.$col_id.']';
				$cell_settings = array_merge(
					(isset($ftype->default_cell_settings) ? $ftype->default_cell_settings : array()),
					(isset($col['settings']) ? $col['settings'] : array())
				);
				$cell_data = isset($row[$col_id]) ? $row[$col_id] : '';
				$r .= '<td class="'.($row_count % 2 ? 'tableCellTwo' : 'tableCellOne').' '.$col['type'].'">'
				    .   $ftype->display_cell($cell_name, $cell_data, $cell_settings)
				    . '</td>';
				$col_count++;
			}
			$r .= '</tr>';
		}

		$r .=   '</table>'
		    . '</div>';

		$LANG->fetch_language_file('ff_matrix');

		// add json lib if < PHP 5.2
		include_once 'includes/jsonwrapper/jsonwrapper.php';

		$js = 'jQuery(window).bind("load", function() {' . NL
		    . '  jQuery.fn.ffMatrix.lang.addRow = "'.$LANG->line('add_row').'";' . NL
		    . '  jQuery.fn.ffMatrix.lang.deleteRow = "'.$LANG->line('delete_row').'";' . NL
		    . '  jQuery.fn.ffMatrix.lang.confirmDeleteRow = "'.$LANG->line('confirm_delete_row').'";' . NL
		    . '  jQuery.fn.ffMatrix.lang.sortRow = "'.$LANG->line('sort_row').'";' . NL
		    . '  jQuery("#'.$field_name.'").ffMatrix("'.$field_name.'", '.json_encode($cell_defaults).');' . NL
		    . '});';

		$this->insert_js($js);

		return $r;
	}

	/**
	 * Save Field
	 * 
	 * @param  mixed   $field_data      The field's current value
	 * @param  array   $field_settings  The field's settings
	 * @param  string  $entry_id        The entry ID
	 * @return array   Modified $field_settings
	 */
	function save_field($field_data, $field_settings, $entry_id)
	{
		$ftypes = $this->_get_ftypes();

		$r = array();

		foreach($field_data as $this->row_count => $row)
		{
			$include_row = FALSE;

			foreach($row as $this->col_id => &$cell_data)
			{
				$col = $field_settings['cols'][$this->col_id];
				$ftype = $ftypes[$col['type']];
				if (method_exists($ftype, 'save_cell'))
				{
					$cell_settings = array_merge(
						(isset($ftype->default_cell_settings) ? $ftype->default_cell_settings : array()),
						(isset($col['settings']) ? $col['settings'] : array())
					);
					$cell_data = $ftype->save_cell($cell_data, $cell_settings, $entry_id);
				}

				if ( ! $include_row AND $cell_data) $include_row = TRUE;
			}

			if ($include_row) $r[] = $row;
		}

		if (isset($this->row_count)) unset($this->row_count);
		if (Isset($this->col_id)) unset($this->col_id);

		return $r;
	}

	/**
	 * Display Tag
	 *
	 * @param  array   $params          Name/value pairs from the opening tag
	 * @param  string  $tagdata         Chunk of tagdata between field tag pairs
	 * @param  string  $field_data      Currently saved field value
	 * @param  array   $field_settings  The field's settings
	 * @return string  Modified $tagdata
	 */
	function display_tag($params, $tagdata, $field_data, $field_settings)
	{
		global $FF, $TMPL;

		$r = '';

		if ($field_settings['cols'] AND $field_data)
		{
			$table_mode = $tagdata ? FALSE : TRUE;
			if ($table_mode)
			{
				$r .= '<table cellspacing="'.$params['cellspacing'].'" cellpadding="'.$params['cellpadding'].'">' . "\n"
				    . '  <thead>' . "\n"
				    . '    <tr>' . "\n";
				$tagdata = '    <tr>' . "\n";
				foreach($field_settings['cols'] as $col_id => $col)
				{
					$r .= '      <th scope="col">'.$col['label'].'</th>' . "\n";
					$tagdata .= '      <td>'.LD.$col['name'].RD.'</td>' . "\n";
				}
				$r .= '    </tr>' . "\n"
				    . '  </thead>' . "\n"
				    . '  <tbody>' . "\n";
				$tagdata .= '    </tr>' . "\n";
			}

			if ($params['sort'] == 'desc')
			{
				$field_data = array_reverse($field_data);
			}

			if ($params['limit'] AND count($field_data) > $params['limit'])
			{
				array_splice($field_data, $params['limit']);
			}

			$ftypes = $this->_get_ftypes();
			$total_rows = count($field_data);

			// prepare for {switch} and {row_count} tags
			$this->prep_iterators($tagdata);
			$this->_count_tag = 'row_count';

			foreach($field_data as $row_count => $row)
			{
				$row_tagdata = $tagdata;

				foreach($field_settings['cols'] as $col_id => $col)
				{
					$ftype = $ftypes[$col['type']];
					$cell_data = isset($row[$col_id]) ? $row[$col_id] : '';
					$cell_settings = array_merge(
						(isset($ftype->default_cell_settings) ? $ftype->default_cell_settings : array()),
						(isset($col['settings']) ? $col['settings'] : array())
					);
					$FF->_parse_tagdata($row_tagdata, $col['name'], $cell_data, $cell_settings, $ftype);
				}

				// var swaps
				$row_tagdata = $TMPL->swap_var_single('total_rows', $total_rows, $row_tagdata);

				// parse {switch} and {row_count} tags
				$this->parse_iterators($row_tagdata);

				$r .= $row_tagdata;
			}

			if ($table_mode)
			{
				$r .= '  </tbody>' . "\n"
				    . '</table>';
			}

			if ($params['backspace'])
			{
				$r = substr($r, 0, -$params['backspace']);
			}
		}

		return $r;
	}

	/**
	 * Total Rows
	 *
	 * @param  array   $params          Name/value pairs from the opening tag
	 * @param  string  $tagdata         Chunk of tagdata between field tag pairs
	 * @param  string  $field_data      Currently saved field value
	 * @param  array   $field_settings  The field's settings
	 * @return string  Number of total rows
	 */
	function total_rows($params, $tagdata, $field_data, $field_settings)
	{
		return count($field_data);
	}

}


class Ff_matrix_text extends Fieldframe_Fieldtype {

	var $_class_name = 'ff_matrix_text';

	var $info = array(
		'name' => 'Text',
		'no_lang' => TRUE
	);

	var $default_cell_settings = array(
		'maxl' => '128'
	);

	function display_cell_settings($cell_settings)
	{
		global $DSP, $LANG;

		$r = '<label class="itemWrapper">'
		   . $DSP->input_text('maxl', $cell_settings['maxl'], '3', '3', 'input', '30px') . NBS
		   . $LANG->line('field_max_length')
		   . '</label>';

		return $r;
	}

	function display_cell($cell_name, $cell_data, $cell_settings)
	{
		global $DSP;
		return $DSP->input_text($cell_name, $cell_data, '', $cell_settings['maxl'], '', '95%');
	}

}


class Ff_matrix_textarea extends Fieldframe_Fieldtype {

	var $_class_name = 'ff_matrix_textarea';

	var $info = array(
		'name' => 'Textarea',
		'no_lang' => TRUE
	);

	var $default_cell_settings = array(
		'rows' => '2'
	);

	function display_cell_settings($cell_settings)
	{
		global $DSP, $LANG;

		$r = '<label class="itemWrapper">'
		   . $DSP->input_text('rows', $cell_settings['rows'], '3', '3', 'input', '30px') . NBS
		   . $LANG->line('textarea_rows')
		   . '</label>';

		return $r;
	}

	function display_cell($cell_name, $cell_data, $cell_settings)
	{
		global $DSP;
		return $DSP->input_textarea($cell_name, $cell_data, $cell_settings['rows'], '', '95%');
	}

}


class Ff_matrix_select extends Fieldframe_Fieldtype {

	var $_class_name = 'ff_matrix_select';

	var $info = array(
		'name' => 'Select',
		'no_lang' => TRUE
	);

	var $default_cell_settings = array(
		'options' => array(
			'Opt 1' => 'Opt 1',
			'Opt 2' => 'Opt 2'
		)
	);

	function display_cell_settings($cell_settings)
	{
		global $DSP, $LANG;

		$r = '<label class="itemWrapper">'
		   . $DSP->qdiv('defaultBold', $LANG->line('field_list_items'))
		   . $DSP->input_textarea('options', $this->options_setting($cell_settings['options']), '3', 'textarea', '140px')
		   . '</label>';

		return $r;
	}

	function save_cell_settings($cell_settings)
	{
		$cell_settings['options'] = $this->save_options_setting($cell_settings['options']);
		return $cell_settings;
	}

	function display_cell($cell_name, $cell_data, $cell_settings)
	{
		$SD = new Fieldframe_SettingsDisplay();
		return $SD->select($cell_name, $cell_data, $cell_settings['options']);
	}

}


class Ff_matrix_date extends Fieldframe_Fieldtype {

	var $_class_name = 'ff_matrix_date';

	var $info = array(
		'name' => 'Date',
		'no_lang' => TRUE
	);

	var $default_tag_params = array(
		'format' => '%F %d %Y'
	);

	function display_cell($cell_name, $cell_data, $cell_settings)
	{
		global $DSP, $LOC, $LANG;

		$LANG->fetch_language_file('search');

		$cell_data = ($cell_data AND is_numeric($cell_data)) ? $LOC->set_human_time($cell_data) : '';
		$r = $DSP->input_text($cell_name, $cell_data, '', '23', '', '140px') . NBS
		   . '<a style="cursor:pointer;" onclick="jQuery(this).prev().val(\''.$LOC->set_human_time($LOC->now).'\');" >'.$LANG->line('today').'</a>';

		return $r;
	}

	function save_cell($cell_data, $cell_settings)
	{
		global $LOC;
		return $cell_data ? $LOC->convert_human_date_to_gmt($cell_data) : '';
	}

	function display_tag($params, $tagdata, $field_data, $field_settings)
	{
		global $LOC;
		if ($params['format'])
		{
			$field_data = $LOC->decode_date($params['format'], $field_data);
		}

		return $field_data;
	}

}


/* End of file ft.ff_matrix.php */
/* Location: ./system/fieldtypes/ff_matrix/ft.ff_matrix.php */
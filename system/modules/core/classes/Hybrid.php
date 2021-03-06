<?php

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2012 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 * 
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 * 
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5.3
 * @copyright  Leo Feyer 2005-2012
 * @author     Leo Feyer <http://www.contao.org>
 * @package    Frontend
 * @license    LGPL
 */


/**
 * Run in a custom namespace, so the class can be replaced
 */
namespace Contao;


/**
 * Class Hybrid
 *
 * Parent class for objects that can be modules or content elements.
 * @copyright  Leo Feyer 2005-2012
 * @author     Leo Feyer <http://www.contao.org>
 * @package    Controller
 */
abstract class Hybrid extends \Frontend
{

	/**
	 * Key
	 * @var string
	 */
	protected $strKey;

	/**
	 * Table
	 * @var string
	 */
	protected $strTable;

	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate;

	/**
	 * Model
	 * @var Model
	 */
	protected $objModel;

	/**
	 * Current record
	 * @var array
	 */
	protected $arrData = array();

	/**
	 * Style array
	 * @var array
	 */
	protected $arrStyle = array();


	/**
	 * Initialize the object
	 * @param object
	 */
	public function __construct($objElement)
	{
		parent::__construct();

		if ($this->strKey == '' || $this->strTable == '')
		{
			return;
		}

		$strModelClass = $this->getModelClassFromTable($this->strTable);

		// Load the model
		if ($this->classFileExists($strModelClass))
		{
			$objHybrid = $strModelClass::findByPk($objElement->{$this->strKey});

			if ($objHybrid === null)
			{
				return;
			}

			$this->objModel = $objHybrid;
		}
		// Directly query the database (backwards compatibility)
		else
		{
			$objHybrid = $this->Database->prepare("SELECT * FROM " . $this->strTable . " WHERE id=?")
										->limit(1)
										->execute($objElement->{$this->strKey});

			if ($objHybrid->numRows < 1)
			{
				return;
			}
		}

		$this->arrData = $objHybrid->row();

		// Get space and CSS ID from the parent element (!)
		$this->space = deserialize($objElement->space);
		$this->cssID = deserialize($objElement->cssID, true);

		$this->typePrefix = $objElement->typePrefix;

		$arrHeadline = deserialize($objElement->headline);
		$this->headline = is_array($arrHeadline) ? $arrHeadline['value'] : $arrHeadline;
		$this->hl = is_array($arrHeadline) ? $arrHeadline['unit'] : 'h1';
	}


	/**
	 * Set an object property
	 * @param string
	 * @param mixed
	 * @return void
	 */
	public function __set($strKey, $varValue)
	{
		$this->arrData[$strKey] = $varValue;
	}


	/**
	 * Return an object property
	 * @param string
	 * @return mixed
	 */
	public function __get($strKey)
	{
		return $this->arrData[$strKey];
	}


	/**
	 * Check whether a property is set
	 * @param string
	 * @return boolean
	 */
	public function __isset($strKey)
	{
		return isset($this->arrData[$strKey]);
	}


	/**
	 * Parse the template
	 * @return string
	 */
	public function generate()
	{
		if ($this->arrData['space'][0] != '')
		{
			$this->arrStyle[] = 'margin-top:'.$this->arrData['space'][0].'px;';
		}

		if ($this->arrData['space'][1] != '')
		{
			$this->arrStyle[] = 'margin-bottom:'.$this->arrData['space'][1].'px;';
		}

		$this->Template = new \FrontendTemplate($this->strTemplate);
		$this->Template->setData($this->arrData);

		$this->compile();

		$this->Template->style = !empty($this->arrStyle) ? implode(' ', $this->arrStyle) : '';
		$this->Template->cssID = ($this->cssID[0] != '') ? ' id="' . $this->cssID[0] . '"' : '';
		$this->Template->class = trim($this->typePrefix . $this->strKey . ' ' . $this->cssID[1]);

		if ($this->Template->headline == '')
		{
			$this->Template->headline = $this->headline;
		}

		if ($this->Template->hl == '')
		{
			$this->Template->hl = $this->hl;
		}

		return $this->Template->parse();
	}


	/**
	 * Compile the current element
	 * @return void
	 */
	abstract protected function compile();
}

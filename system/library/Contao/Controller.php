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
 * @package    System
 * @license    LGPL
 */


/**
 * Run in a custom namespace, so the class can be replaced
 */
namespace Contao;


/**
 * Class Controller
 *
 * Provide methods to manage controllers.
 * @copyright  Leo Feyer 2005-2012
 * @author     Leo Feyer <http://www.contao.org>
 * @package    Controller
 */
abstract class Controller extends \System
{

	/**
	 * Return the current theme as string
	 * @return string
	 */
	protected function getTheme()
	{
		$theme = $GLOBALS['TL_CONFIG']['backendTheme'];

		if ($theme != '' && $theme != 'default' && is_dir(TL_ROOT . '/system/themes/' . $theme))
		{
			return $theme;
		}

		return 'default';
	}


	/**
	 * Find a particular template file and return its path
	 * @param string
	 * @param string
	 * @return string
	 * @throws \Exception
	 */
	protected function getTemplate($strTemplate, $strFormat='html5')
	{
		$arrAllowed = trimsplit(',', $GLOBALS['TL_CONFIG']['templateFiles']);
		array_push($arrAllowed, 'html5'); // see #3398

		if (!in_array($strFormat, $arrAllowed))
		{
			throw new \Exception("Invalid output format $strFormat");
		}

		$strTemplate = basename($strTemplate);

		// Check for a theme folder
		if (TL_MODE == 'FE')
		{
			global $objPage;
			$strCustom = str_replace('../', '', $objPage->templateGroup);

			if ($strCustom != '')
			{
				return \TemplateLoader::getPath($strTemplate, $strFormat, $strCustom);
			}
		}

		return \TemplateLoader::getPath($strTemplate, $strFormat);
	}


	/**
	 * Return all template files of a particular group as array
	 * @param string
	 * @param integer
	 * @return array
	 * @throws \Exception
	 */
	protected function getTemplateGroup($strPrefix, $intTheme=0)
	{
		$strTplFolder = 'templates';
		$arrTemplates = TemplateLoader::getPrefixedFiles($strPrefix);

		// Check for a theme templates folder
		if ($intTheme > 0)
		{
			$objTheme = \ThemeModel::findByPk($intTheme);

			if ($objTheme !== null && $objTheme->templates != '')
			{
				$strTplFolder = $objTheme->templates;
			}
		}

		// Scan the templates directory
		$arrFiles = array_values(preg_grep('/^' . $strPrefix . '/', scan(TL_ROOT . '/' . $strTplFolder)));

		if (!empty($arrFiles))
		{
			foreach ($arrFiles as $strFile)
			{
				$arrTemplates[] = basename($strFile, strrchr($strFile, '.'));
			}
		}

		natcasesort($arrTemplates);
		$arrTemplates = array_values(array_unique($arrTemplates));

		return $arrTemplates;
	}


	/**
	 * Generate a front end module and return it as HTML string
	 * @param mixed
	 * @param string
	 * @return string
	 */
	protected function getFrontendModule($intId, $strColumn='main')
	{
		if (!is_object($intId) && !strlen($intId))
		{
			return '';
		}

		global $objPage;

		// Articles
		if ($intId == 0)
		{
			// Show a particular article only
			if ($this->Input->get('articles') && $objPage->type == 'regular')
			{
				list($strSection, $strArticle) = explode(':', $this->Input->get('articles'));

				if ($strArticle === null)
				{
					$strArticle = $strSection;
					$strSection = 'main';
				}

				if ($strSection == $strColumn)
				{
					$strBuffer = $this->getArticle($strArticle);

					// Send a 404 header if the article does not exist
					if ($strBuffer === false)
					{
						// Do not index the page
						$objPage->noSearch = 1;
						$objPage->cache = 0;

						header('HTTP/1.1 404 Not Found');
						return '<p class="error">' . sprintf($GLOBALS['TL_LANG']['MSC']['invalidPage'], $strArticle) . '</p>';
					}

					return $strBuffer;
				}
			}

			// HOOK: trigger the article_raster_designer extension
			elseif (in_array('article_raster_designer', $this->Config->getActiveModules()))
			{
				return \RasterDesigner::load($objPage->id, $strColumn);
			}

			// Show all articles
			else
			{
				$objArticles = \ArticleModel::findPublishedByPidAndColumn($objPage->id, $strColumn);

				if ($objArticles === null)
				{
					return '';
				}

				$return = '';
				$blnMultiMode = ($objArticles->count() > 1);

				while ($objArticles->next())
				{
					$return .= $this->getArticle($objArticles, $blnMultiMode, false, $strColumn);
				}

				return $return;
			}
		}

		// Other modules
		else
		{
			if (is_object($intId))
			{
				$objRow = $intId;
			}
			else
			{
				$objRow = \ModuleModel::findByPk($intId);
			}

			if ($objRow === null)
			{
				return '';
			}

			// Show to guests only
			if ($objRow->guests && FE_USER_LOGGED_IN && !BE_USER_LOGGED_IN && !$objRow->protected)
			{
				return '';
			}

			// Protected element
			if (!BE_USER_LOGGED_IN && $objRow->protected)
			{
				if (!FE_USER_LOGGED_IN)
				{
					return '';
				}

				$this->import('FrontendUser', 'User');
				$groups = deserialize($objRow->groups);
	
				if (!is_array($groups) || empty($groups) || !count(array_intersect($groups, $this->User->groups)))
				{
					return '';
				}
			}

			$strClass = $this->findFrontendModule($objRow->type);

			// Return if the class does not exist
			if (!$this->classFileExists($strClass))
			{
				$this->log('Module class "'.$GLOBALS['FE_MOD'][$objRow->type].'" (module "'.$objRow->type.'") does not exist', 'Controller getFrontendModule()', TL_ERROR);
				return '';
			}

			$objRow->typePrefix = 'mod_';
			$objModule = new $strClass($objRow, $strColumn);
			$strBuffer = $objModule->generate();

			// HOOK: add custom logic
			if (isset($GLOBALS['TL_HOOKS']['getFrontendModule']) && is_array($GLOBALS['TL_HOOKS']['getFrontendModule']))
			{
				foreach ($GLOBALS['TL_HOOKS']['getFrontendModule'] as $callback)
				{
					$this->import($callback[0]);
					$strBuffer = $this->$callback[0]->$callback[1]($objRow, $strBuffer);
				}
			}

			// Disable indexing if protected
			if ($objModule->protected && !preg_match('/^\s*<!-- indexer::stop/i', $strBuffer))
			{
				$strBuffer = "\n<!-- indexer::stop -->". $strBuffer ."<!-- indexer::continue -->\n";
			}

			return $strBuffer;
		}
	}


	/**
	 * Generate an article and return it as string
	 * @param integer|object
	 * @param boolean
	 * @param boolean
	 * @param string
	 * @return string|boolean
	 */
	protected function getArticle($varId, $blnMultiMode=false, $blnIsInsertTag=false, $strColumn='main')
	{
		global $objPage;

		if (is_object($varId))
		{
			$objRow = $varId;
		}
		else
		{		
			if (!$varId)
			{
				return '';
			}

			$objRow = \ArticleModel::findByIdOrAliasAndPid($varId, (!$blnIsInsertTag ? $objPage->id : null));
		}

		// Return if the article does not exist
		if ($objRow === null)
		{
			return false;
		}

		// Print the article as PDF
		if ($this->Input->get('pdf') == $objRow->id)
		{
			// Backwards compatibility
			if ($objRow->printable == 1)
			{
				$this->printArticleAsPdf($objRow);
			}
			elseif ($objRow->printable != '')
			{
				$options = deserialize($objRow->printable);

				if (is_array($options) && in_array('pdf', $options))
				{
					$this->printArticleAsPdf($objRow);
				}
			}
		}

		$objRow->headline = $objRow->title;
		$objRow->multiMode = $blnMultiMode;

		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['getArticle']) && is_array($GLOBALS['TL_HOOKS']['getArticle']))
		{
			foreach ($GLOBALS['TL_HOOKS']['getArticle'] as $callback)
			{
				$this->import($callback[0]);
				$this->$callback[0]->$callback[1]($objRow);
			}
		}

		$objArticle = new \ModuleArticle($objRow, $strColumn);
		return $objArticle->generate($blnIsInsertTag);
	}


	/**
	 * Generate a content element and return it as HTML string
	 * @param integer|object
	 * @return string
	 */
	protected function getContentElement($intId)
	{
		if (is_object($intId))
		{
			$objRow = $intId;
		}
		else
		{		
			if (!strlen($intId) || $intId < 1)
			{
				return '';
			}

			$objRow = \ContentModel::findByPk($intId);

			if ($objRow === null)
			{
				return '';
			}
		}

		// Show to guests only
		if ($objRow->guests && FE_USER_LOGGED_IN && !BE_USER_LOGGED_IN && !$objRow->protected)
		{
			return '';
		}

		// Protected the element
		if ($objRow->protected && !BE_USER_LOGGED_IN)
		{
			if (!FE_USER_LOGGED_IN)
			{
				return '';
			}

			$this->import('FrontendUser', 'User');
			$groups = deserialize($objRow->groups);

			if (!is_array($groups) || count($groups) < 1 || count(array_intersect($groups, $this->User->groups)) < 1)
			{
				return '';
			}
		}

		// Remove the spacing in the back end preview
		if (TL_MODE == 'BE')
		{
			$objRow->space = null;
		}

		$strClass = $this->findContentElement($objRow->type);

		// Return if the class does not exist
		if (!$this->classFileExists($strClass))
		{
			$this->log('Content element class "'.$strClass.'" (content element "'.$objRow->type.'") does not exist', 'Controller getContentElement()', TL_ERROR);
			return '';
		}

		$objRow->typePrefix = 'ce_';
		$objElement = new $strClass($objRow);
		$strBuffer = $objElement->generate();

		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['getContentElement']) && is_array($GLOBALS['TL_HOOKS']['getContentElement']))
		{
			foreach ($GLOBALS['TL_HOOKS']['getContentElement'] as $callback)
			{
				$this->import($callback[0]);
				$strBuffer = $this->$callback[0]->$callback[1]($objRow, $strBuffer);
			}
		}

		// Disable indexing if protected
		if ($objElement->protected && !preg_match('/^\s*<!-- indexer::stop/i', $strBuffer))
		{
			$strBuffer = "\n<!-- indexer::stop -->". $strBuffer ."<!-- indexer::continue -->\n";
		}

		return $strBuffer;
	}


	/**
	 * Generate a form and return it as HTML string
	 * @param integer|object
	 * @return string
	 */
	protected function getForm($varId)
	{
		if (is_object($varId))
		{
			$objRow = $varId;
		}
		else
		{
			if ($varId == '')
			{
				return '';
			}

			$objRow = \FormModel::findByIdOrAlias($varId);

			if ($objRow === null)
			{
				return '';
			}
		}

		$objRow->typePrefix = 'ce_';
		$objRow->form = $objRow->id;
		$objElement = new Form($objRow);
		$strBuffer = $objElement->generate();

		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['getForm']) && is_array($GLOBALS['TL_HOOKS']['getForm']))
		{
			foreach ($GLOBALS['TL_HOOKS']['getForm'] as $callback)
			{
				$this->import($callback[0]);
				$strBuffer = $this->$callback[0]->$callback[1]($objRow, $strBuffer);
			}
		}

		return $strBuffer;
	}


	/**
	 * Get the details of a page including inherited parameters and return it as object
	 * @param integer|object
	 * @return \Contao\Model|null
	 */
	protected function getPageDetails($intId)
	{
		if (is_object($intId))
		{
			$objPage = $intId;
			$intId = $objPage->id;

			$this->import('Cache');
			$strKey = __METHOD__ . '-' . $objPage->id;

			if (isset($this->Cache->$strKey))
			{
				return $this->Cache->$strKey;
			}
		}
		else
		{
			if (!strlen($intId) || $intId < 1)
			{
				return null;
			}

			$this->import('Cache');
			$strKey = __METHOD__ . '-' . $intId;

			if (isset($this->Cache->$strKey))
			{
				return $this->Cache->$strKey;
			}

			$objPage = \PageModel::findByPk($intId);

			if ($objPage === null)
			{
				return null;
			}
		}

		// Set some default values
		$objPage->protected = (boolean) $objPage->protected;
		$objPage->groups = $objPage->protected ? deserialize($objPage->groups) : false;
		$objPage->layout = $objPage->includeLayout ? $objPage->layout : false;
		$objPage->mobileLayout = $objPage->includeLayout ? $objPage->mobileLayout : false;
		$objPage->cache = $objPage->includeCache ? $objPage->cache : false;

		$pid = $objPage->pid;
		$type = $objPage->type;
		$alias = $objPage->alias;
		$name = $objPage->title;
		$title = $objPage->pageTitle ?: $objPage->title;
		$folderUrl = basename($objPage->alias);
		$palias = '';
		$pname = '';
		$ptitle = '';
		$trail = array($intId, $pid);

		// Load all parent pages
		$objParentPage = \PageModel::findParentsById($pid);

		// Inherit settings
		if ($objParentPage !== null)
		{
			while ($objParentPage->next() && $pid > 0 && $type != 'root')
			{
				$pid = $objParentPage->pid;
				$type = $objParentPage->type;

				// Parent title
				if ($ptitle == '')
				{
					$palias = $objParentPage->alias;
					$pname = $objParentPage->title;
					$ptitle = $objParentPage->pageTitle ?: $objParentPage->title;
				}

				// Page title
				if ($type != 'root')
				{
					$alias = $objParentPage->alias;
					$name = $objParentPage->title;
					$title = $objParentPage->pageTitle ?: $objParentPage->title;
					$folderUrl = basename($alias) . '/' . $folderUrl;
					$trail[] = $objParentPage->pid;
				}

				// Cache
				if ($objParentPage->includeCache && $objPage->cache === false)
				{
					$objPage->cache = $objParentPage->cache;
				}

				// Layout
				if ($objParentPage->includeLayout)
				{
					if ($objPage->layout === false)
					{
						$objPage->layout = $objParentPage->layout;
					}
					if ($objPage->mobileLayout === false)
					{
						$objPage->mobileLayout = $objParentPage->mobileLayout;
					}
				}

				// Protection
				if ($objParentPage->protected && $objPage->protected === false)
				{
					$objPage->protected = true;
					$objPage->groups = deserialize($objParentPage->groups);
				}
			}
		}

		// Set the titles
		$objPage->mainAlias = $alias;
		$objPage->mainTitle = $name;
		$objPage->mainPageTitle = $title;
		$objPage->parentAlias = $palias;
		$objPage->parentTitle = $pname;
		$objPage->parentPageTitle = $ptitle;
		$objPage->folderUrl = $folderUrl;

		// Set the root ID and title
		if ($objParentPage !== null && $objParentPage->type == 'root')
		{
			$objPage->rootId = $objParentPage->id;
			$objPage->rootTitle = $objParentPage->pageTitle ?: $objParentPage->title;
			$objPage->domain = $objParentPage->dns;
			$objPage->rootLanguage = $objParentPage->language;
			$objPage->language = $objParentPage->language;
			$objPage->staticFiles = $objParentPage->staticFiles;
			$objPage->staticSystem = $objParentPage->staticSystem;
			$objPage->staticPlugins = $objParentPage->staticPlugins;
			$objPage->dateFormat = $objParentPage->dateFormat;
			$objPage->timeFormat = $objParentPage->timeFormat;
			$objPage->datimFormat = $objParentPage->datimFormat;
			$objPage->adminEmail = $objParentPage->adminEmail;

			// Store whether the root page has been published
			$time = time();
			$objPage->rootIsPublic = ($objParentPage->published && ($objParentPage->start == '' || $objParentPage->start < $time) && ($objParentPage->stop == '' || $objParentPage->stop > $time));
			$objPage->rootIsFallback = ($objParentPage->fallback != '');
		}

		// No root page found
		elseif (TL_MODE == 'FE' && $objPage->type != 'root')
		{
			header('HTTP/1.1 404 Not Found');
			$this->log('Page ID "'. $objPage->id .'" does not belong to a root page', 'Controller getPageDetails()', TL_ERROR);
			die('No root page found');
		}

		$objPage->trail = array_reverse($trail);

		// Remove insert tags from all titles (see #2853)
		$objPage->title = strip_insert_tags($objPage->title);
		$objPage->pageTitle = strip_insert_tags($objPage->pageTitle);
		$objPage->parentTitle = strip_insert_tags($objPage->parentTitle);
		$objPage->parentPageTitle = strip_insert_tags($objPage->parentPageTitle);
		$objPage->mainTitle = strip_insert_tags($objPage->mainTitle);
		$objPage->mainPageTitle = strip_insert_tags($objPage->mainPageTitle);
		$objPage->rootTitle = strip_insert_tags($objPage->rootTitle);

		// Do not cache protected pages
		if ($objPage->protected)
		{
			$objPage->cache = 0;
		}

		$this->Cache->$strKey = $objPage;
		return $objPage;
	}


	/**
	 * Return all page sections as array
	 * @return array
	 */
	protected function getPageSections()
	{
		return array_merge(array('header', 'left', 'right', 'main', 'footer'), trimsplit(',', $GLOBALS['TL_CONFIG']['customSections']));
	}


	/**
	 * Return all languages as array
	 * @param boolean
	 * @return array
	 */
	protected function getLanguages($blnBeOnly=false)
	{
		$return = array();
		$languages = array();
		$arrAux = array();
		$langsNative = array();

		$this->loadLanguageFile('languages');
		include TL_ROOT . '/system/config/languages.php';

		foreach ($languages as $strKey=>$strName)
		{
			$arrAux[$strKey] = isset($GLOBALS['TL_LANG']['LNG'][$strKey]) ? utf8_romanize($GLOBALS['TL_LANG']['LNG'][$strKey]) : $strName;
		}

		asort($arrAux);
		$arrBackendLanguages = scan(TL_ROOT . '/system/modules/core/languages');

		foreach (array_keys($arrAux) as $strKey)
		{
			if ($blnBeOnly && !in_array($strKey, $arrBackendLanguages))
			{
				continue;
			}

			$return[$strKey] = isset($GLOBALS['TL_LANG']['LNG'][$strKey]) ? $GLOBALS['TL_LANG']['LNG'][$strKey] : $languages[$strKey];

			if (isset($langsNative[$strKey]) && $langsNative[$strKey] != $return[$strKey])
			{
				$return[$strKey] .= ' - ' . $langsNative[$strKey];
			}
		}

		return $return;
	}


	/**
	 * Return an array of supported back end languages
	 * @return array
	 */
	protected function getBackendLanguages()
	{
		return $this->getLanguages(true);
	}


	/**
	 * Return all back end themes as array
	 * @return array
	 */
	public function getBackendThemes()
	{
		$arrReturn = array();
		$arrThemes = scan(TL_ROOT . '/system/themes');

		foreach ($arrThemes as $strTheme)
		{
			if (strncmp($strTheme, '.', 1) === 0 || !is_dir(TL_ROOT . '/system/themes/' . $strTheme))
			{
				continue;
			}

			$arrReturn[$strTheme] = $strTheme;
		}

		return $arrReturn;
	}


	/**
	 * Return all counties as array
	 * @return array
	 */
	protected function getCountries()
	{
		$return = array();
		$countries = array();
		$arrAux = array();

		$this->loadLanguageFile('countries');
		include TL_ROOT . '/system/config/countries.php';

		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['getCountries']) && is_array($GLOBALS['TL_HOOKS']['getCountries']))
		{
			foreach ($GLOBALS['TL_HOOKS']['getCountries'] as $callback)
			{
				$this->import($callback[0]);
				$return = $this->$callback[0]->$callback[1]($return);
			}
		}

		foreach ($countries as $strKey=>$strName)
		{
			$arrAux[$strKey] = isset($GLOBALS['TL_LANG']['CNT'][$strKey]) ? utf8_romanize($GLOBALS['TL_LANG']['CNT'][$strKey]) : $strName;
		}

		asort($arrAux);

		foreach (array_keys($arrAux) as $strKey)
		{
			$return[$strKey] = isset($GLOBALS['TL_LANG']['CNT'][$strKey]) ? $GLOBALS['TL_LANG']['CNT'][$strKey] : $countries[$strKey];
		}

		return $return;
	}


	/**
	 * Return all timezones as array
	 * @return array
	 */
	protected function getTimeZones()
	{
		$arrReturn = array();
		$timezones = array();

		require TL_ROOT . '/system/config/timezones.php';

		foreach ($timezones as $strGroup=>$arrTimezones)
		{
			foreach ($arrTimezones as $strTimezone)
			{
				$arrReturn[$strGroup][] = $strTimezone;
			}
		}

		return $arrReturn;
	}


	/**
	 * Resize or crop an image
	 * @param string
	 * @param integer
	 * @param integer
	 * @param string
	 * @return boolean
	 */
	protected function resizeImage($image, $width, $height, $mode='')
	{
		return $this->getImage($image, $width, $height, $mode, $image) ? true : false;
	}


	/**
	 * Resize an image
	 * @param string
	 * @param integer
	 * @param integer
	 * @param string
	 * @param string
	 * @return string|null
	 */
	protected function getImage($image, $width, $height, $mode='', $target=null)
	{
		if ($image == '')
		{
			return null;
		}

		$image = rawurldecode($image);

		// Check whether the file exists
		if (!file_exists(TL_ROOT . '/' . $image))
		{
			$this->log('Image "' . $image . '" could not be found', 'Controller getImage()', TL_ERROR);
			return null;
		}

		$objFile = new \File($image);
		$arrAllowedTypes = trimsplit(',', strtolower($GLOBALS['TL_CONFIG']['validImageTypes']));

		// Check the file type
		if (!in_array($objFile->extension, $arrAllowedTypes))
		{
			$this->log('Image type "' . $objFile->extension . '" was not allowed to be processed', 'Controller getImage()', TL_ERROR);
			return null;
		}

		// No resizing required
		if ($objFile->width == $width && $objFile->height == $height)
		{
			return $image;
		}

		// No mode given
		if ($mode == '')
		{
			$mode = 'proportional';
		}

		// Backwards compatibility
		if ($mode == 'crop')
		{
			$mode = 'center_center';
		}

		$strCacheKey = substr(md5('-w' . $width . '-h' . $height . '-' . $image . '-' . $mode . '-' . $objFile->mtime), 0, 8);
		$strCacheName = 'assets/images/' . substr($strCacheKey, -1) . '/' . $objFile->filename . '-' . $strCacheKey . '.' . $objFile->extension;

		// Return the path of the new image if it exists already
		if (!$GLOBALS['TL_CONFIG']['bypassCache'] && file_exists(TL_ROOT . '/' . $strCacheName))
		{
			return $this->urlEncode($strCacheName);
		}

		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['getImage']) && is_array($GLOBALS['TL_HOOKS']['getImage']))
		{
			foreach ($GLOBALS['TL_HOOKS']['getImage'] as $callback)
			{
				$this->import($callback[0]);
				$return = $this->$callback[0]->$callback[1]($image, $width, $height, $mode, $strCacheName, $objFile, $target);

				if (is_string($return))
				{
					return $this->urlEncode($return);
				}
			}
		}

		// Return the path to the original image if the GDlib cannot handle it
		if (!extension_loaded('gd') || !$objFile->isGdImage || $objFile->width > $GLOBALS['TL_CONFIG']['gdMaxImgWidth'] || $objFile->height > $GLOBALS['TL_CONFIG']['gdMaxImgHeight'] || (!$width && !$height) || $width > 1200 || $height > 1200)
		{
			return $this->urlEncode($image);
		}

		$intPositionX = 0;
		$intPositionY = 0;
		$intWidth = $width;
		$intHeight = $height;

		// Mode-specific changes
		if ($intWidth && $intHeight)
		{
			switch ($mode)
			{
				case 'proportional':
					if ($objFile->width >= $objFile->height)
					{
						unset($height, $intHeight);
					}
					else
					{
						unset($width, $intWidth);
					}
					break;

				case 'box':
					if (ceil($objFile->height * $width / $objFile->width) <= $intHeight)
					{
						unset($height, $intHeight);
					}
					else
					{
						unset($width, $intWidth);
					}
					break;
			}
		}

		// Resize width and height and crop the image if necessary
		if ($intWidth && $intHeight)
		{
			if (($intWidth * $objFile->height) != ($intHeight * $objFile->width))
			{
				$intWidth = ceil($objFile->width * $height / $objFile->height);
				$intPositionX = -intval(($intWidth - $width) / 2);

				if ($intWidth < $width)
				{
					$intWidth = $width;
					$intHeight = ceil($objFile->height * $width / $objFile->width);
					$intPositionX = 0;
					$intPositionY = -intval(($intHeight - $height) / 2);
				}
			}

			// Advanced crop modes
			switch ($mode)
			{
				case 'left_top':
					$intPositionX = 0;
					$intPositionY = 0;
					break;

				case 'center_top':
					$intPositionX = -intval(($intWidth - $width) / 2);
					$intPositionY = 0;
					break;

				case 'right_top':
					$intPositionX = -intval($intWidth - $width);
					$intPositionY = 0;
					break;

				case 'left_center':
					$intPositionX = 0;
					$intPositionY = -intval(($intHeight - $height) / 2);
					break;

				case 'center_center':
					$intPositionX = -intval(($intWidth - $width) / 2);
					$intPositionY = -intval(($intHeight - $height) / 2);
					break;

				case 'right_center':
					$intPositionX = -intval($intWidth - $width);
					$intPositionY = -intval(($intHeight - $height) / 2);
					break;

				case 'left_bottom':
					$intPositionX = 0;
					$intPositionY = -intval($intHeight - $height);
					break;

				case 'center_bottom':
					$intPositionX = -intval(($intWidth - $width) / 2);
					$intPositionY = -intval($intHeight - $height);
					break;

				case 'right_bottom':
					$intPositionX = -intval($intWidth - $width);
					$intPositionY = -intval($intHeight - $height);
					break;
			}

			$strNewImage = imagecreatetruecolor($width, $height);
		}

		// Calculate the height if only the width is given
		elseif ($intWidth)
		{
			$intHeight = ceil($objFile->height * $width / $objFile->width);
			$strNewImage = imagecreatetruecolor($intWidth, $intHeight);
		}

		// Calculate the width if only the height is given
		elseif ($intHeight)
		{
			$intWidth = ceil($objFile->width * $height / $objFile->height);
			$strNewImage = imagecreatetruecolor($intWidth, $intHeight);
		}

		$arrGdinfo = gd_info();
		$strGdVersion = preg_replace('/[^0-9\.]+/', '', $arrGdinfo['GD Version']);

		switch ($objFile->extension)
		{
			case 'gif':
				if ($arrGdinfo['GIF Read Support'])
				{
					$strSourceImage = imagecreatefromgif(TL_ROOT . '/' . $image);
					$intTranspIndex = imagecolortransparent($strSourceImage);

					// Handle transparency
					if ($intTranspIndex >= 0 && $intTranspIndex < imagecolorstotal($strSourceImage))
					{
						$arrColor = imagecolorsforindex($strSourceImage, $intTranspIndex);
						$intTranspIndex = imagecolorallocate($strNewImage, $arrColor['red'], $arrColor['green'], $arrColor['blue']);
						imagefill($strNewImage, 0, 0, $intTranspIndex);
						imagecolortransparent($strNewImage, $intTranspIndex);
					}
				}
				break;

			case 'jpg':
			case 'jpeg':
				if ($arrGdinfo['JPG Support'] || $arrGdinfo['JPEG Support'])
				{
					$strSourceImage = imagecreatefromjpeg(TL_ROOT . '/' . $image);
				}
				break;

			case 'png':
				if ($arrGdinfo['PNG Support'])
				{
					$strSourceImage = imagecreatefrompng(TL_ROOT . '/' . $image);

					// Handle transparency (GDlib >= 2.0 required)
					if (version_compare($strGdVersion, '2.0', '>='))
					{
						imagealphablending($strNewImage, false);
						$intTranspIndex = imagecolorallocatealpha($strNewImage, 0, 0, 0, 127);
						imagefill($strNewImage, 0, 0, $intTranspIndex);
						imagesavealpha($strNewImage, true);
					}
				}
				break;
		}

		// The new image could not be created
		if (!$strSourceImage)
		{
			imagedestroy($strNewImage);
			$this->log('Image "' . $image . '" could not be processed', 'Controller getImage()', TL_ERROR);
			return null;
		}

		imagecopyresampled($strNewImage, $strSourceImage, $intPositionX, $intPositionY, 0, 0, $intWidth, $intHeight, $objFile->width, $objFile->height);

		// Fallback to PNG if GIF ist not supported
		if ($objFile->extension == 'gif' && !$arrGdinfo['GIF Create Support'])
		{
			$objFile->extension = 'png';
		}

		// Create the new image
		switch ($objFile->extension)
		{
			case 'gif':
				imagegif($strNewImage, TL_ROOT . '/' . $strCacheName);
				break;

			case 'jpg':
			case 'jpeg':
				imagejpeg($strNewImage, TL_ROOT . '/' . $strCacheName, (!$GLOBALS['TL_CONFIG']['jpgQuality'] ? 80 : $GLOBALS['TL_CONFIG']['jpgQuality']));
				break;

			case 'png':
				// Optimize non-truecolor images (see #2426)
				if (version_compare($strGdVersion, '2.0', '>=') && function_exists('imagecolormatch') && !imageistruecolor($strSourceImage))
				{
					// TODO: make it work with transparent images, too
					if (imagecolortransparent($strSourceImage) == -1)
					{
						$intColors = imagecolorstotal($strSourceImage);

						// Convert to a palette image
						// @see http://www.php.net/manual/de/function.imagetruecolortopalette.php#44803
						if ($intColors > 0 && $intColors < 256)
						{
							$wi = imagesx($strNewImage);
							$he = imagesy($strNewImage);
							$ch = imagecreatetruecolor($wi, $he);
							imagecopymerge($ch, $strNewImage, 0, 0, 0, 0, $wi, $he, 100);
							imagetruecolortopalette($strNewImage, false, $intColors);
							imagecolormatch($ch, $strNewImage);
							imagedestroy($ch);
						}
					}
				}

				imagepng($strNewImage, TL_ROOT . '/' . $strCacheName);
				break;
		}

		// Destroy the temporary images
		imagedestroy($strSourceImage);
		imagedestroy($strNewImage);

		// Resize the original image
		if ($target)
		{
			$this->import('Files');
			$this->Files->rename($strCacheName, $target);
			return $this->urlEncode($target);
		}

		// Set the file permissions when the Safe Mode Hack is used
		if ($GLOBALS['TL_CONFIG']['useFTP'])
		{
			$this->import('Files');
			$this->Files->chmod($strCacheName, 0644);
		}

		// Return the path to new image
		return $this->urlEncode($strCacheName);
	}


	/**
	 * Return true for backwards compatibility (see #3218)
	 * @return boolean
	 * @deprecated
	 */
	protected function getDatePickerString()
	{
		return true;
	}


	/**
	 * Return the current languages to be used with the TinyMCE spellchecker
	 * @return string
	 */
	protected function getSpellcheckerString()
	{
		$this->loadLanguageFile('languages');

		$return = array();
		$langs = scan(TL_ROOT . '/system/modules/core/languages');
		array_unshift($langs, $GLOBALS['TL_LANGUAGE']);

		foreach ($langs as $lang)
		{
			if (isset($GLOBALS['TL_LANG']['LNG'][$lang]))
			{
				$return[$lang] = $GLOBALS['TL_LANG']['LNG'][$lang] . '=' . $lang;
			}
		}

		return '+' . implode(',', array_unique($return));
	}


	/**
	 * Calculate the status icon name based on the page parameters
	 * @param object
	 * @return string
	 */
	protected function getPageStatusIcon($objPage)
	{
		$sub = 0;
		$image = $objPage->type.'.gif';

		// Page not published or not active
		if (!$objPage->published || $objPage->start && $objPage->start > time() || $objPage->stop && $objPage->stop < time())
		{
			$sub += 1;
		}

		// Page hidden from menu
		if ($objPage->hide && !in_array($objPage->type, array('redirect', 'forward', 'root', 'error_403', 'error_404')))
		{
			$sub += 2;
		}

		// Page protected
		if ($objPage->protected && !in_array($objPage->type, array('root', 'error_403', 'error_404')))
		{
			$sub += 4;
		}

		// Get the image name
		if ($sub > 0)
		{
			$image = $objPage->type.'_'.$sub.'.gif';
		}

		return $image;
	}


	/**
	 * Print an article as PDF and stream it to the browser
	 * @param object
	 * @return void
	 */
	protected function printArticleAsPdf($objArticle)
	{
		$objArticle->headline = $objArticle->title;
		$objArticle->printable = false;

		// Generate article
		$objArticle = new \ModuleArticle($objArticle);
		$strArticle = $this->replaceInsertTags($objArticle->generate());
		$strArticle = html_entity_decode($strArticle, ENT_QUOTES, $GLOBALS['TL_CONFIG']['characterSet']);
		$strArticle = $this->convertRelativeUrls($strArticle, '', true);

		// Remove form elements and JavaScript links
		$arrSearch = array
		(
			'@<form.*</form>@Us',
			'@<a [^>]*href="[^"]*javascript:[^>]+>.*</a>@Us'
		);

		$strArticle = preg_replace($arrSearch, '', $strArticle);

		// HOOK: allow individual PDF routines
		if (isset($GLOBALS['TL_HOOKS']['printArticleAsPdf']) && is_array($GLOBALS['TL_HOOKS']['printArticleAsPdf']))
		{
			foreach ($GLOBALS['TL_HOOKS']['printArticleAsPdf'] as $callback)
			{
				$this->import($callback[0]);
				$this->$callback[0]->$callback[1]($strArticle, $objArticle);
			}
		}

		// Handle line breaks in preformatted text
		$strArticle = preg_replace_callback('@(<pre.*</pre>)@Us', 'nl2br_callback', $strArticle);

		// Default PDF export using TCPDF
		$arrSearch = array
		(
			'@<span style="text-decoration: ?underline;?">(.*)</span>@Us',
			'@(<img[^>]+>)@',
			'@(<div[^>]+block[^>]+>)@',
			'@[\n\r\t]+@',
			'@<br( /)?><div class="mod_article@',
			'@href="([^"]+)(pdf=[0-9]*(&|&amp;)?)([^"]*)"@'
		);

		$arrReplace = array
		(
			'<u>$1</u>',
			'<br>$1',
			'<br>$1',
			' ',
			'<div class="mod_article',
			'href="$1$4"'
		);

		$strArticle = preg_replace($arrSearch, $arrReplace, $strArticle);

		// TCPDF configuration
		$l['a_meta_dir'] = 'ltr';
		$l['a_meta_charset'] = $GLOBALS['TL_CONFIG']['characterSet'];
		$l['a_meta_language'] = $GLOBALS['TL_LANGUAGE'];
		$l['w_page'] = 'page';

		// Include library
		require_once TL_ROOT . '/system/config/tcpdf.php';
		require_once TL_ROOT . '/system/library/TCPDF/tcpdf.php';

		// Create new PDF document
		$pdf = new \TCPDF(PDF_PAGE_ORIENTATION, PDF_UNIT, PDF_PAGE_FORMAT, true);

		// Set document information
		$pdf->SetCreator(PDF_CREATOR);
		$pdf->SetAuthor(PDF_AUTHOR);
		$pdf->SetTitle($objArticle->title);
		$pdf->SetSubject($objArticle->title);
		$pdf->SetKeywords($objArticle->keywords);

		// Prevent font subsetting (huge speed improvement)
		$pdf->setFontSubsetting(false);

		// Remove default header/footer
		$pdf->setPrintHeader(false);
		$pdf->setPrintFooter(false);

		// Set margins
		$pdf->SetMargins(PDF_MARGIN_LEFT, PDF_MARGIN_TOP, PDF_MARGIN_RIGHT);

		// Set auto page breaks
		$pdf->SetAutoPageBreak(true, PDF_MARGIN_BOTTOM);

		// Set image scale factor
		$pdf->setImageScale(PDF_IMAGE_SCALE_RATIO);

		// Set some language-dependent strings
		$pdf->setLanguageArray($l);

		// Initialize document and add a page
		$pdf->AliasNbPages();
		$pdf->AddPage();

		// Set font
		$pdf->SetFont(PDF_FONT_NAME_MAIN, '', PDF_FONT_SIZE_MAIN);

		// Write the HTML content
		$pdf->writeHTML($strArticle, true, 0, true, 0);

		// Close and output PDF document
		$pdf->lastPage();
		$pdf->Output(standardize(ampersand($objArticle->title, false)) . '.pdf', 'D');

		// Stop script execution
		exit;
	}


	/**
	 * Replace insert tags with their values
	 * @param string
	 * @param boolean
	 * @return string
	 */
	protected function replaceInsertTags($strBuffer, $blnCache=false)
	{
		global $objPage;

		// Preserve insert tags
		if ($GLOBALS['TL_CONFIG']['disableInsertTags'])
		{
			return $this->restoreBasicEntities($strBuffer);
		}

		$tags = preg_split('/\{\{([^\}]+)\}\}/', $strBuffer, -1, PREG_SPLIT_DELIM_CAPTURE);

		$strBuffer = '';
		$arrCache = array();

		for($_rit=0; $_rit<count($tags); $_rit=$_rit+2)
		{
			$strBuffer .= $tags[$_rit];
			$strTag = $tags[$_rit+1];

			// Skip empty tags
			if ($strTag == '')
			{
				continue;
			}

			// Load value from cache array
			if (isset($arrCache[$strTag]))
			{
				$strBuffer .= $arrCache[$strTag];
				continue;
			}

			$elements = explode('::', $strTag);

			// Skip certain elements if the output will be cached
			if ($blnCache)
			{
				if ($elements[0] == 'date' || $elements[0] == 'ua' || $elements[0] == 'file' || $elements[1] == 'back' || $elements[1] == 'referer' || $elements[0] == 'request_token' || strncmp($elements[0], 'cache_', 6) === 0)
				{
					$strBuffer .= '{{' . $strTag . '}}';
					continue;
				}
			}

			$arrCache[$strTag] = '';

			// Replace the tag
			switch (strtolower($elements[0]))
			{
				// Date
				case 'date':
					$arrCache[$strTag] = $this->parseDate($elements[1] ?: $GLOBALS['TL_CONFIG']['dateFormat']);
					break;

				// Accessibility tags
				case 'lang':
					if ($elements[1] == '')
					{
						$arrCache[$strTag] = '</span>';
					}
					elseif ($objPage->outputFormat == 'xhtml')
					{
						$arrCache[$strTag] = '<span lang="' . $elements[1] . '" xml:lang="' . $elements[1] . '">';
					}
					else
					{
						$arrCache[$strTag] = $arrCache[$strTag] = '<span lang="' . $elements[1] . '">';
					}
					break;

				// E-mail addresses
				case 'email':
				case 'email_open':
				case 'email_url':
					if ($elements[1] == '')
					{
						$arrCache[$strTag] = '';
						break;
					}

					$this->import('String');
					$strEmail = $this->String->encodeEmail($elements[1]);

					// Replace the tag
					switch (strtolower($elements[0]))
					{
						case 'email':
							$arrCache[$strTag] = '<a href="&#109;&#97;&#105;&#108;&#116;&#111;&#58;' . $strEmail . '" class="email">' . preg_replace('/\?.*$/', '', $strEmail) . '</a>';
							break;

						case 'email_open':
							$arrCache[$strTag] = '<a href="&#109;&#97;&#105;&#108;&#116;&#111;&#58;' . $strEmail . '" class="email">';
							break;

						case 'email_url':
							$arrCache[$strTag] = $strEmail;
							break;
					}
					break;

				// Label tags
				case 'label':
					$keys = explode(':', $elements[1]);

					if (count($keys) < 2)
					{
						$arrCache[$strTag] = '';
						break;
					}

					$this->loadLanguageFile($keys[0]);

					if (count($keys) == 2)
					{
						$arrCache[$strTag] = $GLOBALS['TL_LANG'][$keys[0]][$keys[1]];
					}
					else
					{
						$arrCache[$strTag] = $GLOBALS['TL_LANG'][$keys[0]][$keys[1]][$keys[2]];
					}
					break;

				// Front end user
				case 'user':
					if (FE_USER_LOGGED_IN)
					{
						$this->import('FrontendUser', 'User');
						$value = $this->User->$elements[1];

						if ($value == '')
						{
							$arrCache[$strTag] = $value;
							break;
						}

						$this->loadDataContainer('tl_member');

						if ($GLOBALS['TL_DCA']['tl_member']['fields'][$elements[1]]['inputType'] == 'password')
						{
							$arrCache[$strTag] = '';
							break;
						}

						$value = deserialize($value);
						$rgxp = $GLOBALS['TL_DCA']['tl_member']['fields'][$elements[1]]['eval']['rgxp'];
						$opts = $GLOBALS['TL_DCA']['tl_member']['fields'][$elements[1]]['options'];
						$rfrc = $GLOBALS['TL_DCA']['tl_member']['fields'][$elements[1]]['reference'];

						if ($rgxp == 'date')
						{
							$arrCache[$strTag] = $this->parseDate($GLOBALS['TL_CONFIG']['dateFormat'], $value);
						}
						elseif ($rgxp == 'time')
						{
							$arrCache[$strTag] = $this->parseDate($GLOBALS['TL_CONFIG']['timeFormat'], $value);
						}
						elseif ($rgxp == 'datim')
						{
							$arrCache[$strTag] = $this->parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $value);
						}
						elseif (is_array($value))
						{
							$arrCache[$strTag] = implode(', ', $value);
						}
						elseif (is_array($opts) && array_is_assoc($opts))
						{
							$arrCache[$strTag] = isset($opts[$value]) ? $opts[$value] : $value;
						}
						elseif (is_array($rfrc))
						{
							$arrCache[$strTag] = isset($rfrc[$value]) ? ((is_array($rfrc[$value])) ? $rfrc[$value][0] : $rfrc[$value]) : $value;
						}
						else
						{
							$arrCache[$strTag] = $value;
						}

						// Convert special characters (see #1890)
						$arrCache[$strTag] = specialchars($arrCache[$strTag]);
					}
					break;

				// Link
				case 'link':
				case 'link_open':
				case 'link_url':
				case 'link_title':
					// Back link
					if ($elements[1] == 'back')
					{
						$strUrl = 'javascript:history.go(-1)';
						$strTitle = $GLOBALS['TL_LANG']['MSC']['goBack'];

						// No language files if the page is cached
						if (!strlen($strTitle))
						{
							$strTitle = 'Go back';
						}

						$strName = $strTitle;
					}

					// External links
					elseif (strncmp($elements[1], 'http://', 7) === 0 || strncmp($elements[1], 'https://', 8) === 0)
					{
						$strUrl = $elements[1];
						$strTitle = $elements[1];
						$strName = str_replace(array('http://', 'https://'), '', $elements[1]);
					}

					// Regular link
					else
					{
						// User login page
						if ($elements[1] == 'login')
						{
							if (!FE_USER_LOGGED_IN)
							{
								break;
							}

							$this->import('FrontendUser', 'User');
							$elements[1] = $this->User->loginPage;
						}

						$objNextPage = \PageModel::findByIdOrAlias($elements[1]);

						if ($objNextPage === null)
						{
							break;
						}

						// Page type specific settings (thanks to Andreas Schempp)
						switch ($objNextPage->type)
						{
							case 'redirect':
								$strUrl = $objNextPage->url;

								if (strncasecmp($strUrl, 'mailto:', 7) === 0)
								{
									$this->import('String');
									$strUrl = $this->String->encodeEmail($strUrl);
								}
								break;

							case 'forward':
								if (($objTarget = $objNextPage->getRelated('jumpTo')) !== null)
								{
									$strUrl = $this->generateFrontendUrl($objTarget->row());
									break;
								}
								elseif (($objTarget = \PageModel::findFirstPublishedRegularByPid($objNextPage->id)) !== null)
								{
									if ($GLOBALS['TL_CONFIG']['addLanguageToUrl'])
									{
										$objTarget = $this->getPageDetails($objTarget); // see #3983
										$strUrl = $this->generateFrontendUrl($objTarget->row(), null, $objTarget->language);
									}
									else
									{
										$strUrl = $this->generateFrontendUrl($objTarget->row());
									}
									break;
								}
								// DO NOT ADD A break; STATEMENT

							default:
								if ($GLOBALS['TL_CONFIG']['addLanguageToUrl'])
								{
									$objNextPage = $this->getPageDetails($objNextPage); // see #3983
									$strUrl = $this->generateFrontendUrl($objNextPage->row(), null, $objNextPage->language);
								}
								else
								{
									$strUrl = $this->generateFrontendUrl($objNextPage->row());
								}
								break;
						}

						$strName = $objNextPage->title;
						$strTarget = $objNextPage->target ? (($objPage->outputFormat == 'xhtml') ? LINK_NEW_WINDOW : ' target="_blank"') : '';
						$strTitle = $objNextPage->pageTitle ?: $objNextPage->title;
					}

					// Replace the tag
					switch (strtolower($elements[0]))
					{
						case 'link':
							$arrCache[$strTag] = sprintf('<a href="%s" title="%s"%s>%s</a>', $strUrl, specialchars($strTitle), $strTarget, specialchars($strName));
							break;

						case 'link_open':
							$arrCache[$strTag] = sprintf('<a href="%s" title="%s"%s>', $strUrl, specialchars($strTitle), $strTarget);
							break;

						case 'link_url':
							$arrCache[$strTag] = $strUrl;
							break;

						case 'link_title':
							$arrCache[$strTag] = specialchars($strTitle);
							break;

						case 'link_target':
							$arrCache[$strTag] = $strTarget;
							break;
					}
					break;

				// Closing link tag
				case 'link_close':
					$arrCache[$strTag] = '</a>';
					break;

				// Insert article
				case 'insert_article':
					if (($strOutput = $this->getArticle($elements[1], false, true)) !== false)
					{
						$arrCache[$strTag] = $this->replaceInsertTags(ltrim($strOutput));
					}
					else
					{
						$arrCache[$strTag] = '<p class="error">' . sprintf($GLOBALS['TL_LANG']['MSC']['invalidPage'], $elements[1]) . '</p>';
					}
					break;

				// Insert content element
				case 'insert_content':
					$arrCache[$strTag] = $this->replaceInsertTags($this->getContentElement($elements[1]));
					break;

				// Insert module
				case 'insert_module':
					$arrCache[$strTag] = $this->replaceInsertTags($this->getFrontendModule($elements[1]));
					break;

				// Insert form
				case 'insert_form':
					$arrCache[$strTag] = $this->replaceInsertTags($this->getForm($elements[1]));
					break;

				// Article
				case 'article':
				case 'article_open':
				case 'article_url':
				case 'article_title':
					$objArticle = \ArticleModel::findByIdOrAlias($elements[1]);

					if ($objArticle === null)
					{
						break;
					}
					else
					{
						$strUrl = $this->generateFrontendUrl($objArticle->row(), '/articles/' . ((!$GLOBALS['TL_CONFIG']['disableAlias'] && strlen($objArticle->alias)) ? $objArticle->alias : $objArticle->id));
					}
	
					// Replace the tag
					switch (strtolower($elements[0]))
					{
						case 'article':
							$strLink = specialchars($objArticle->title);
							$arrCache[$strTag] = sprintf('<a href="%s" title="%s">%s</a>', $strUrl, $strLink, $strLink);
							break;

						case 'article_open':
							$arrCache[$strTag] = sprintf('<a href="%s" title="%s">', $strUrl, specialchars($objArticle->title));
							break;

						case 'article_url':
							$arrCache[$strTag] = $strUrl;
							break;

						case 'article_title':
							$arrCache[$strTag] = specialchars($objArticle->title);
							break;
					}
					break;

				// FAQ
				case 'faq':
				case 'faq_open':
				case 'faq_url':
				case 'faq_title':
					$objFaq = \FaqModel::findByIdOrAlias($elements[1]);

					if ($objFaq === null)
					{
						break;
					}
					else
					{
						$strUrl = $this->generateFrontendUrl($objFaq->row(), ($GLOBALS['TL_CONFIG']['useAutoItem'] ?  '/' : '/items/') . ((!$GLOBALS['TL_CONFIG']['disableAlias'] && $objFaq->alias != '') ? $objFaq->alias : $objFaq->id));
					}
	
					// Replace the tag
					switch (strtolower($elements[0]))
					{
						case 'faq':
							$strLink = specialchars($objFaq->question);
							$arrCache[$strTag] = sprintf('<a href="%s" title="%s">%s</a>', $strUrl, $strLink, $strLink);
							break;

						case 'faq_open':
							$arrCache[$strTag] = sprintf('<a href="%s" title="%s">', $strUrl, specialchars($objFaq->question));
							break;

						case 'faq_url':
							$arrCache[$strTag] = $strUrl;
							break;

						case 'faq_title':
							$arrCache[$strTag] = specialchars($objFaq->question);
							break;
					}
					break;

				// News
				case 'news':
				case 'news_open':
				case 'news_url':
				case 'news_title':
					$objNews = \NewsModel::findByIdOrAlias($elements[1]);

					if ($objNews === null)
					{
						break;
					}
					elseif ($objNews->source == 'internal')
					{
						$strUrl = $this->generateFrontendUrl($objNews->getRelated('jumpTo')->row());
					}
					elseif ($objNews->source == 'article')
					{
						$objArticle = \ArticleModel::findByPk($objNews->articleId, array('eager'=>true));
						$strUrl = $this->generateFrontendUrl($objArticle->pid, '/articles/' . ((!$GLOBALS['TL_CONFIG']['disableAlias'] && $objArticle->alias != '') ? $objArticle->alias : $objArticle->id));
					}
					elseif ($objNews->source == 'external')
					{
						$strUrl = $objNews->url;
					}
					else
					{
						$strUrl = $this->generateFrontendUrl($objNews->pid, ($GLOBALS['TL_CONFIG']['useAutoItem'] ?  '/' : '/items/') . ((!$GLOBALS['TL_CONFIG']['disableAlias'] && $objNews->alias != '') ? $objNews->alias : $objNews->id));
					}
	
					// Replace the tag
					switch (strtolower($elements[0]))
					{
						case 'news':
							$strLink = specialchars($objNews->headline);
							$arrCache[$strTag] = sprintf('<a href="%s" title="%s">%s</a>', $strUrl, $strLink, $strLink);
							break;

						case 'news_open':
							$arrCache[$strTag] = sprintf('<a href="%s" title="%s">', $strUrl, specialchars($objNews->headline));
							break;

						case 'news_url':
							$arrCache[$strTag] = $strUrl;
							break;

						case 'news_title':
							$arrCache[$strTag] = specialchars($objNews->headline);
							break;
					}
					break;

				// Events
				case 'event':
				case 'event_open':
				case 'event_url':
				case 'event_title':
					$objEvent = \CalendarEventsModel::findByIdOrAlias($elements[1]);

					if ($objEvent === null)
					{
						break;
					}
					elseif ($objEvent->source == 'internal')
					{
						$strUrl = $this->generateFrontendUrl($objEvent->getRelated('jumpTo')->row());
					}
					elseif ($objEvent->source == 'article')
					{
						$objArticle = \ArticleModel::findByPk($objEvent->articleId, array('eager'=>true));
						$strUrl = $this->generateFrontendUrl($objArticle->pid, '/articles/' . ((!$GLOBALS['TL_CONFIG']['disableAlias'] && $objArticle->alias != '') ? $objArticle->alias : $objArticle->id));
					}
					elseif ($objEvent->source == 'external')
					{
						$strUrl = $objEvent->url;
					}
					else
					{
						$strUrl = $this->generateFrontendUrl($objEvent->pid, ($GLOBALS['TL_CONFIG']['useAutoItem'] ?  '/' : '/events/') . ((!$GLOBALS['TL_CONFIG']['disableAlias'] && $objEvent->alias != '') ? $objEvent->alias : $objEvent->id));
					}

					// Replace the tag
					switch (strtolower($elements[0]))
					{
						case 'event':
							$strLink = specialchars($objEvent->title);
							$arrCache[$strTag] = sprintf('<a href="%s" title="%s">%s</a>', $strUrl, $strLink, $strLink);
							break;

						case 'event_open':
							$arrCache[$strTag] = sprintf('<a href="%s" title="%s">', $strUrl, specialchars($objEvent->title));
							break;

						case 'event_url':
							$arrCache[$strTag] = $strUrl;
							break;

						case 'event_title':
							$arrCache[$strTag] = specialchars($objEvent->title);
							break;
					}
					break;

				// Article teaser
				case 'article_teaser':
					$objTeaser = \ArticleModel::findByIdOrAlias($elements[1]);

					if ($objTeaser !== null)
					{
						$this->import('String');

						if ($objPage->outputFormat == 'xhtml')
						{
							$arrCache[$strTag] = $this->String->toXhtml($this->replaceInsertTags($objTeaser->teaser));
						}
						else
						{
							$arrCache[$strTag] = $this->String->toHtml5($this->replaceInsertTags($objTeaser->teaser));
						}
					}
					break;

				// News teaser
				case 'news_teaser':
					$objTeaser = \NewsModel::findByIdOrAlias($elements[1]);

					if ($objTeaser !== null)
					{
						$this->import('String');

						if ($objPage->outputFormat == 'xhtml')
						{
							$arrCache[$strTag] = $this->String->toXhtml($objTeaser->teaser);
						}
						else
						{
							$arrCache[$strTag] = $this->String->toHtml5($objTeaser->teaser);
						}
					}
					break;

				// Event teaser
				case 'event_teaser':
					$objTeaser = \CalendarEventsModel::findByIdOrAlias($elements[1]);

					if ($objTeaser !== null)
					{
						$this->import('String');

						if ($objPage->outputFormat == 'xhtml')
						{
							$arrCache[$strTag] = $this->String->toXhtml($objTeaser->teaser);
						}
						else
						{
							$arrCache[$strTag] = $this->String->toHtml5($objTeaser->teaser);
						}
					}
					break;

				// News feed URL
				case 'news_feed': # FIXME: feeds are now in tl_calendar_feed
					$objFeed = \NewsArchiveModel::findByPk($elements[1]);

					if ($objFeed !== null)
					{
						$arrCache[$strTag] = $objFeed->feedBase . $objFeed->alias . '.xml';
					}
					break;

				// Calendar feed URL
				case 'calendar_feed': # FIXME: feeds are now in tl_calendar_feed
					$objFeed = \CalendarModel::findByPk($elements[1]);

					if ($objFeed !== null)
					{
						$arrCache[$strTag] = $objFeed->feedBase . $objFeed->alias . '.xml';
					}
					break;

				// Last update
				case 'last_update':
					$objUpdate = \Database::getInstance()->execute("SELECT MAX(tstamp) AS tc, (SELECT MAX(tstamp) FROM tl_news) AS tn, (SELECT MAX(tstamp) FROM tl_calendar_events) AS te FROM tl_content");

					if ($objUpdate->numRows)
					{
						$arrCache[$strTag] = $this->parseDate($elements[1] ?: $GLOBALS['TL_CONFIG']['datimFormat'], max($objUpdate->tc, $objUpdate->tn, $objUpdate->te));
					}
					break;

				// Version
				case 'version':
					$arrCache[$strTag] = VERSION . '.' . BUILD;
					break;

				// Request token
				case 'request_token':
					$arrCache[$strTag] = REQUEST_TOKEN;
					break;

				// Conditional tags
				case 'iflng':
					if ($elements[1] != '' && $elements[1] != $objPage->language)
					{
						for ($_rit; $_rit<count($tags); $_rit+=2)
						{
							if ($tags[$_rit+1] == 'iflng')
							{
								break;
							}
						}
					}
					unset($arrCache[$strTag]);
					break;

				case 'ifnlng':
					if ($elements[1] != '')
					{
						$langs = trimsplit(',', $elements[1]);

						if (in_array($objPage->language, $langs))
						{
							for ($_rit; $_rit<count($tags); $_rit+=2)
							{
								if ($tags[$_rit+1] == 'ifnlng')
								{
									break;
								}
							}
						}
					}
					unset($arrCache[$strTag]);
					break;

				// Environment
				case 'env':
					switch ($elements[1])
					{
						case 'host':
							$arrCache[$strTag] = $this->Environment->host;
							break;

						case 'http_host':
							$arrCache[$strTag] = $this->Environment->httpHost;
							break;

						case 'url':
							$arrCache[$strTag] = $this->Environment->url;
							break;

						case 'path':
							$arrCache[$strTag] = $this->Environment->base;
							break;

						case 'request':
							$arrCache[$strTag] = $this->getIndexFreeRequest(true);
							break;

						case 'ip':
							$arrCache[$strTag] = $this->Environment->ip;
							break;

						case 'referer':
							$arrCache[$strTag] = $this->getReferer(true);
							break;

						case 'files_url':
							$arrCache[$strTag] = TL_FILES_URL;
							break;

						case 'script_url':
							$arrCache[$strTag] = TL_SCRIPT_URL;
							break;

						case 'plugins_url':
							$arrCache[$strTag] = TL_PLUGINS_URL;
							break;
					}
					break;

				// Page
				case 'page':
					if ($elements[1] == 'pageTitle' && $objPage->pageTitle == '')
					{
						$elements[1] = 'title';
					}
					elseif ($elements[1] == 'parentPageTitle' && $objPage->parentPageTitle == '')
					{
						$elements[1] = 'parentTitle';
					}
					elseif ($elements[1] == 'mainPageTitle' && $objPage->mainPageTitle == '')
					{
						$elements[1] = 'mainTitle';
					}

					$arrCache[$strTag] = specialchars($objPage->{$elements[1]});
					break;

				// User agent
				case 'ua':
					$ua = $this->Environment->agent;

					if ($elements[1] != '')
					{
						$arrCache[$strTag] = $ua->{$elements[1]};
					}
					else
					{
						$arrCache[$strTag] = '';
					}
					break;

				// Acronyms
				case 'acronym':
					if ($objPage->outputFormat == 'xhtml')
					{
						if ($elements[1] != '')
						{
							$arrCache[$strTag] = '<acronym title="'. $elements[1] .'">';
						}
						else
						{
							$arrCache[$strTag] = '</acronym>';
						}
						break;
					}
					// NO break;

				// Abbreviations
				case 'abbr':
					if ($elements[1] != '')
					{
						$arrCache[$strTag] = '<abbr title="'. $elements[1] .'">';
					}
					else
					{
						$arrCache[$strTag] = '</abbr>';
					}
					break;

				// Images
				case 'image':
					$width = null;
					$height = null;
					$alt = '';
					$class = '';
					$rel = '';
					$strFile = $elements[1];
					$mode = '';

					// Take arguments
					if (strpos($elements[1], '?') !== false)
					{
						$this->import('String');

						$arrChunks = explode('?', urldecode($elements[1]), 2);
						$strSource = $this->String->decodeEntities($arrChunks[1]);
						$strSource = str_replace('[&]', '&', $strSource);
						$arrParams = explode('&', $strSource);

						foreach ($arrParams as $strParam)
						{
							list($key, $value) = explode('=', $strParam);

							switch ($key)
							{
								case 'width':
									$width = $value;
									break;

								case 'height':
									$height = $value;
									break;

								case 'alt':
									$alt = specialchars($value);
									break;

								case 'class':
									$class = $value;
									break;

								case 'rel':
									$rel = $value;
									break;

								case 'mode':
									$mode = $value;
									break;
							}
						}

						$strFile = $arrChunks[0];
					}

					// Sanitize path
					$strFile = str_replace('../', '', $strFile);

					// Check maximum image width
					if ($GLOBALS['TL_CONFIG']['maxImageWidth'] > 0 && $width > $GLOBALS['TL_CONFIG']['maxImageWidth'])
					{
						$width = $GLOBALS['TL_CONFIG']['maxImageWidth'];
						$height = null;
					}

					// Generate the thumbnail image
					try
					{
						$src = $this->getImage($strFile, $width, $height, $mode);
						$dimensions = '';

						// Add the image dimensions
						if (($imgSize = @getimagesize(TL_ROOT .'/'. rawurldecode($src))) !== false)
						{
							$dimensions = $imgSize[3];
						}

						// Generate the HTML markup
						if ($rel != '')
						{
							if (strncmp($rel, 'lightbox', 8) !== 0 || $objPage->outputFormat == 'xhtml')
							{
								$attribute = ' rel="' . $rel . '"';
							}
							else
							{
								$attribute = ' data-lightbox="' . substr($rel, 8) . '"';
							}

							$arrCache[$strTag] = '<a href="' . TL_FILES_URL . $strFile . '"' . (($alt != '') ? ' title="' . $alt . '"' : '') . $attribute . '><img src="' . TL_FILES_URL . $src . '" ' . $dimensions . ' alt="' . $alt . '"' . (($class != '') ? ' class="' . $class . '"' : '') . (($objPage->outputFormat == 'xhtml') ? ' />' : '>') . '</a>';
						}
						else
						{
							$arrCache[$strTag] = '<img src="' . TL_FILES_URL . $src . '" ' . $dimensions . ' alt="' . $alt . '"' . (($class != '') ? ' class="' . $class . '"' : '') . (($objPage->outputFormat == 'xhtml') ? ' />' : '>');
						}
					}
					catch (\Exception $e)
					{
						$arrCache[$strTag] = '';
					}
					break;

				// Files from the templates directory
				case 'file':
					$arrGet = $_GET;
					$this->import('Input');
					$this->Input->resetCache();
					$strFile = $elements[1];

					// Take arguments and add them to the $_GET array
					if (strpos($elements[1], '?') !== false)
					{
						$this->import('String');

						$arrChunks = explode('?', urldecode($elements[1]));
						$strSource = $this->String->decodeEntities($arrChunks[1]);
						$strSource = str_replace('[&]', '&', $strSource);
						$arrParams = explode('&', $strSource);

						foreach ($arrParams as $strParam)
						{
							$arrParam = explode('=', $strParam);
							$_GET[$arrParam[0]] = $arrParam[1];
						}

						$strFile = $arrChunks[0];
					}

					// Sanitize path
					$strFile = str_replace('../', '', $strFile);

					// Include .php, .tpl, .xhtml and .html5 files
					if (preg_match('/\.(php|tpl|xhtml|html5)$/', $strFile) && file_exists(TL_ROOT . '/templates/' . $strFile))
					{
						ob_start();
						include TL_ROOT . '/templates/' . $strFile;
						$arrCache[$strTag] = ob_get_contents();
						ob_end_clean();
					}

					$_GET = $arrGet;
					$this->Input->resetCache();
					break;

				// HOOK: pass unknown tags to callback functions
				default:
					if (isset($GLOBALS['TL_HOOKS']['replaceInsertTags']) && is_array($GLOBALS['TL_HOOKS']['replaceInsertTags']))
					{
						foreach ($GLOBALS['TL_HOOKS']['replaceInsertTags'] as $callback)
						{
							$this->import($callback[0]);
							$varValue = $this->$callback[0]->$callback[1]($strTag);

							// Replace the tag and stop the loop
							if ($varValue !== false)
							{
								$arrCache[$strTag] = $varValue;
								break;
							}
						}
					}
					break;
			}

			$strBuffer .= $arrCache[$strTag];
		}

		return $this->restoreBasicEntities($strBuffer);
	}


	/**
	 * Restore basic entities
	 * @param string
	 * @return string
	 */
	protected function restoreBasicEntities($strBuffer)
	{
		return str_replace(array('[&]', '[&amp;]', '[lt]', '[gt]', '[nbsp]', '[-]'), array('&amp;', '&amp;', '&lt;', '&gt;', '&nbsp;', '&shy;'), $strBuffer);
	}


	/**
	 * Parse simple tokens that can be used to personalize newsletters
	 * @param string
	 * @param array
	 * @return string
	 * @throws \Exception
	 */
	protected function parseSimpleTokens($strBuffer, $arrData)
	{
		$strReturn = '';

		// Remove any unwanted tags (especially PHP tags)
		$strBuffer = strip_tags($strBuffer, $GLOBALS['TL_CONFIG']['allowedTags']);
		$arrTags = preg_split('/(\{[^\}]+\})/', $strBuffer, -1, PREG_SPLIT_DELIM_CAPTURE|PREG_SPLIT_NO_EMPTY);

		// Replace the tags
		foreach ($arrTags as $strTag)
		{
			if (strncmp($strTag, '{if', 3) === 0)
			{
				$strReturn .= preg_replace('/\{if ([A-Za-z0-9_]+)([=!<>]+)([^;$\(\)\[\] ]+).*\}/i', '<?php if ($arrData[\'$1\'] $2 $3): ?>', $strTag);
			}
			elseif (strncmp($strTag, '{elseif', 7) === 0)
			{
				$strReturn .= preg_replace('/\{elseif ([A-Za-z0-9_]+)([=!<>]+)([^;$\(\)\[\] ]+).*\}/i', '<?php elseif ($arrData[\'$1\'] $2 $3): ?>', $strTag);
			}
			elseif (strncmp($strTag, '{else', 5) === 0)
			{
				$strReturn .= '<?php else: ?>';
			}
			elseif (strncmp($strTag, '{endif', 6) === 0)
			{
				$strReturn .= '<?php endif; ?>';
			}
			else
			{
				$strReturn .= $strTag;
			}
		}

		// Replace tokens
		$strReturn = str_replace('?><br />', '?>', $strReturn);
		$strReturn = preg_replace('/##([A-Za-z0-9_]+)##/i', '<?php echo $arrData[\'$1\']; ?>', $strReturn);

		// Eval the code
		ob_start();
		$blnEval = eval("?>" . $strReturn);
		$strReturn = ob_get_contents();
		ob_end_clean();

		// Throw an exception if there is an eval() error
		if ($blnEval === false)
		{
			throw new \Exception("Error parsing simple tokens ($strReturn)");
		}

		// Return the evaled code
		return $strReturn;
	}


	/**
	 * Generate an image tag and return it as HTML string
	 * @param string
	 * @param string
	 * @param string
	 * @return string
	 */
	protected function generateImage($src, $alt='', $attributes='')
	{
		if (strpos($src, '/') === false)
		{
			$src = 'system/themes/' . $this->getTheme() . '/images/' . $src;
		}

		if (!file_exists(TL_ROOT .'/'. $src))
		{
			return '';
		}

		$size = getimagesize(TL_ROOT .'/'. $src);
		return '<img src="' . TL_FILES_URL . $this->urlEncode($src) . '" ' . $size[3] . ' alt="' . specialchars($alt) . '"' . (($attributes != '') ? ' ' . $attributes : '') . '>';
	}


	/**
	 * Take an array of four margin values and the current unit and compile the margin style definition
	 * @param array
	 * @param string
	 * @return string
	 */
	protected function generateMargin($arrValues, $strType='margin')
	{
		$top = $arrValues['top'];
		$right = $arrValues['right'];
		$bottom = $arrValues['bottom'];
		$left = $arrValues['left'];

		// Try to shorten the definition
		if ($top != '' && $right != '' && $bottom != '' && $left != '')
		{
			if ($top == $right && $top == $bottom && $top == $left)
			{
				return $strType . ':' . $top . $arrValues['unit'] . ';';
			}
			elseif ($top == $bottom && $right == $left)
			{
				return $strType . ':' . $top . $arrValues['unit'] . ' ' . $left . $arrValues['unit'] . ';';
			}
			elseif ($top != $bottom && $right == $left)
			{
				return $strType . ':' . $top . $arrValues['unit'] . ' ' . $right . $arrValues['unit'] . ' ' . $bottom . $arrValues['unit'] . ';';
			}
			else
			{
				return $strType . ':' . $top . $arrValues['unit'] . ' ' . $right . $arrValues['unit'] . ' ' . $bottom . $arrValues['unit'] . ' ' . $left . $arrValues['unit'] . ';';
			}
		}

		$arrDir = array
		(
			'top'=>$top,
			'right'=>$right,
			'bottom'=>$bottom,
			'left'=>$left
		);

		$return = array();

		foreach ($arrDir as $k=>$v)
		{
			if ($v != '')
			{
				$return[] = $strType . '-' . $k . ':' . $v . $arrValues['unit'] . ';';
			}
		}

		return implode(' ', $return);
	}


	/**
	 * Generate an URL depending on the current rewriteURL setting and return it
	 * @param array
	 * @param string
	 * @param string
	 * @return string
	 */
	protected function generateFrontendUrl(Array $arrRow, $strParams=null, $strForceLang=null)
	{
		if (!$GLOBALS['TL_CONFIG']['disableAlias'])
		{
			$strLanguage = '';

			if ($GLOBALS['TL_CONFIG']['addLanguageToUrl'])
			{
				if ($strForceLang != '')
				{
					$strLanguage = $strForceLang . '/';
				}
				elseif (isset($arrRow['language']) && $arrRow['type'] == 'root')
				{
					$strLanguage = $arrRow['language'] . '/';
				}
				elseif (TL_MODE == 'FE')
				{
					global $objPage;
					$strLanguage = $objPage->rootLanguage . '/';
				}
			}

			// Correctly handle the "index" alias (see #3961)
			if ($arrRow['alias'] == 'index')
			{
				if ($strParams == '')
				{
					$strUrl = ($GLOBALS['TL_CONFIG']['rewriteURL'] ? '' : 'index.php/') . $strLanguage;
				}
				else
				{
					$strUrl = ($GLOBALS['TL_CONFIG']['rewriteURL'] ? '' : 'index.php/') . substr($strLanguage, 0, -1) . $strParams . $GLOBALS['TL_CONFIG']['urlSuffix'];
				}
			}
			else
			{
				$strUrl = ($GLOBALS['TL_CONFIG']['rewriteURL'] ? '' : 'index.php/') . $strLanguage . ($arrRow['alias'] ?: $arrRow['id']) . $strParams . $GLOBALS['TL_CONFIG']['urlSuffix'];
			}
		}
		else
		{
			$strRequest = '';

			if ($strParams != '')
			{
				$arrChunks = explode('/', preg_replace('@^/@', '', $strParams));

				for ($i=0; $i<count($arrChunks); $i=($i+2))
				{
					$strRequest .= sprintf('&%s=%s', $arrChunks[$i], $arrChunks[($i+1)]);
				}
			}

			$strUrl = 'index.php?id=' . $arrRow['id'] . $strRequest;
		}

		// HOOK: add custom logic
		if (isset($GLOBALS['TL_HOOKS']['generateFrontendUrl']) && is_array($GLOBALS['TL_HOOKS']['generateFrontendUrl']))
		{
			foreach ($GLOBALS['TL_HOOKS']['generateFrontendUrl'] as $callback)
			{
				$this->import($callback[0]);
				$strUrl = $this->$callback[0]->$callback[1]($arrRow, $strParams, $strUrl);
			}
		}

		return $strUrl;
	}


	/**
	 * Convert relative URLs to absolute URLs
	 * @param string
	 * @param string
	 * @param boolean
	 * @return string
	 */
	protected function convertRelativeUrls($strContent, $strBase='', $blnHrefOnly=false)
	{
		if ($strBase == '')
		{
			$strBase = $this->Environment->base;
		}

		$search = $blnHrefOnly ? 'href' : 'href|src';
		$arrUrls = preg_split('/(('.$search.')="([^"]+)")/i', $strContent, -1, PREG_SPLIT_DELIM_CAPTURE);
		$strContent = '';

		for($i=0; $i<count($arrUrls); $i=$i+4)
		{
			$strContent .= $arrUrls[$i];

			if (!isset($arrUrls[$i+2]))
			{
				continue;
			}

			$strAttribute = $arrUrls[$i+2];
			$strUrl = $arrUrls[$i+3];

			if (!preg_match('@^(https?://|ftp://|mailto:|#)@i', $strUrl))
			{
				$strUrl = $strBase . (($strUrl != '/') ? $strUrl : '');
			}

			$strContent .= $strAttribute . '="' . $strUrl . '"';
		}

		return $strContent;
	}


	/**
	 * Send a file to the browser so the "save as" dialogue opens
	 * @param string
	 * @return void
	 */
	protected function sendFileToBrowser($strFile)
	{
		// Make sure there are no attempts to hack the file system
		if (preg_match('@^\.+@i', $strFile) || preg_match('@\.+/@i', $strFile) || preg_match('@(://)+@i', $strFile))
		{
			header('HTTP/1.1 404 Not Found');
			die('Invalid file name');
		}

		// Limit downloads to the files directory
		if (!preg_match('@^' . preg_quote($GLOBALS['TL_CONFIG']['uploadPath'], '@') . '@i', $strFile))
		{
			header('HTTP/1.1 404 Not Found');
			die('Invalid path');
		}

		// Check whether the file exists
		if (!file_exists(TL_ROOT . '/' . $strFile))
		{
			header('HTTP/1.1 404 Not Found');
			die('File not found');
		}

		$objFile = new \File($strFile);
		$arrAllowedTypes = trimsplit(',', strtolower($GLOBALS['TL_CONFIG']['allowedDownload']));

		if (!in_array($objFile->extension, $arrAllowedTypes))
		{
			header('HTTP/1.1 403 Forbidden');
			die(sprintf('File type "%s" is not allowed', $objFile->extension));
		}

		// Make sure no output buffer is active
		// @see http://ch2.php.net/manual/en/function.fpassthru.php#74080
		while (@ob_end_clean());

		// Prevent session locking (see #2804)
		session_write_close();

		// Open the "save as …" dialogue
		header('Content-Type: ' . $objFile->mime);
		header('Content-Transfer-Encoding: binary');
		header('Content-Disposition: attachment; filename="' . $objFile->basename . '"');
		header('Content-Length: ' . $objFile->filesize);
		header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
		header('Pragma: public');
		header('Expires: 0');
		header('Connection: close');

		$resFile = fopen(TL_ROOT . '/' . $strFile, 'rb');
		fpassthru($resFile);
		fclose($resFile);

		// HOOK: post download callback
		if (isset($GLOBALS['TL_HOOKS']['postDownload']) && is_array($GLOBALS['TL_HOOKS']['postDownload']))
		{
			foreach ($GLOBALS['TL_HOOKS']['postDownload'] as $callback)
			{
				$this->import($callback[0]);
				$this->$callback[0]->$callback[1]($strFile);
			}
		}

		// Stop script
		exit;
	}


	/**
	 * Load a set of DCA files
	 * @param string
	 * @param boolean
	 * @return void
	 */
	protected function loadDataContainer($strName, $blnNoCache=false)
	{
		// Return if the data has been loaded already
		if (isset($GLOBALS['loadDataContainer'][$strName]) && !$blnNoCache)
		{
			return;
		}

		$strCacheFile = TL_ROOT . '/system/cache/dca/' . $strName . '.php';

		if (!$GLOBALS['TL_CONFIG']['bypassCache'] && file_exists($strCacheFile))
		{
			include $strCacheFile;
		}
		else
		{
			// Generate the cache file
			$objCacheFile = new \File('system/cache/dca/' . $strName . '.php');
			$objCacheFile->write('<?php' . "\n");

			// Parse all module folders
			foreach ($this->Config->getActiveModules() as $strModule)
			{
				$strFile = TL_ROOT . '/system/modules/' . $strModule . '/dca/' . $strName . '.php';

				if (file_exists($strFile))
				{
					$objCacheFile->append(file_get_contents($strFile, null, null, 6));
					include $strFile;
				}
			}

			$objCacheFile->close();
		}

		// HOOK: allow to load custom settings
		if (isset($GLOBALS['TL_HOOKS']['loadDataContainer']) && is_array($GLOBALS['TL_HOOKS']['loadDataContainer']))
		{
			foreach ($GLOBALS['TL_HOOKS']['loadDataContainer'] as $callback)
			{
				$this->import($callback[0]);
				$this->$callback[0]->$callback[1]($strName);
			}
		}

		// Local configuration file
		if (file_exists(TL_ROOT . '/system/config/dcaconfig.php'))
		{
			include TL_ROOT . '/system/config/dcaconfig.php';
		}

		// Use a global cache variable to support nested calls
		$GLOBALS['loadDataContainer'][$strName] = true;
	}


	/**
	 * Convert a back end DCA so it can be used with the widget class
	 * @param array
	 * @param string
	 * @param mixed
	 * @param string
	 * @param string
	 * @return array
	 */
	protected function prepareForWidget($arrData, $strName, $varValue=null, $strField='', $strTable='')
	{
		$arrNew = $arrData['eval'];

		$arrNew['id'] = $strName;
		$arrNew['name'] = $strName;
		$arrNew['strField'] = $strField;
		$arrNew['strTable'] = $strTable;
		$arrNew['label'] = (($label = is_array($arrData['label']) ? $arrData['label'][0] : $arrData['label']) != false) ? $label : $strField;
		$arrNew['description'] = $arrData['label'][1];
		$arrNew['type'] = $arrData['inputType'];
		$arrNew['activeRecord'] = $arrData['activeRecord'];

		// Internet Explorer does not support onchange for checkboxes and radio buttons
		if ($arrData['eval']['submitOnChange'])
		{
			if ($arrData['inputType'] == 'checkbox' || $arrData['inputType'] == 'checkboxWizard' || $arrData['inputType'] == 'radio' || $arrData['inputType'] == 'radioTable')
			{
				$arrNew['onclick'] = trim($arrNew['onclick'] . " Backend.autoSubmit('".$strTable."')");
			}
			else
			{
				$arrNew['onchange'] = trim($arrNew['onchange'] . " Backend.autoSubmit('".$strTable."')");
			}
		}

		$arrNew['allowHtml'] = ($arrData['eval']['allowHtml'] || strlen($arrData['eval']['rte']) || $arrData['eval']['preserveTags']) ? true : false;

		// Decode entities if HTML is allowed
		if ($arrNew['allowHtml'] || $arrData['inputType'] == 'fileTree')
		{
			$arrNew['decodeEntities'] = true;
		}

		// Add Ajax event
		if ($arrData['inputType'] == 'checkbox' && is_array($GLOBALS['TL_DCA'][$strTable]['subpalettes']) && in_array($strField, array_keys($GLOBALS['TL_DCA'][$strTable]['subpalettes'])) && $arrData['eval']['submitOnChange'])
		{
			$arrNew['onclick'] = "AjaxRequest.toggleSubpalette(this, 'sub_".$strName."', '".$strField."')";
		}

		// Options callback
		if (is_array($arrData['options_callback']))
		{
			if (!is_object($arrData['options_callback'][0]))
			{
				$this->import($arrData['options_callback'][0]);
			}

			$arrData['options'] = $this->$arrData['options_callback'][0]->$arrData['options_callback'][1]($this);
		}

		// Foreign key
		elseif (isset($arrData['foreignKey']))
		{
			$arrKey = explode('.', $arrData['foreignKey'], 2);
			$objOptions = $this->Database->execute("SELECT id, " . $arrKey[1] . " AS value FROM " . $arrKey[0] . " WHERE tstamp>0 ORDER BY value");

			if ($objOptions->numRows)
			{
				$arrData['options'] = array();

				while($objOptions->next())
				{
					$arrData['options'][$objOptions->id] = $objOptions->value;
				}
			}
		}

		// Add default option to single checkbox
		if ($arrData['inputType'] == 'checkbox' && !isset($arrData['options']) && !isset($arrData['options_callback']) && !isset($arrData['foreignKey']))
		{
			if (TL_MODE == 'FE' && isset($arrNew['description']))
			{
				$arrNew['options'][] = array('value'=>1, 'label'=>$arrNew['description']);
			}
			else
			{
				$arrNew['options'][] = array('value'=>1, 'label'=>$arrNew['label']);
			}
		}

		// Add options
		if (is_array($arrData['options']))
		{
			$blnIsAssociative = ($arrData['eval']['isAssociative'] || array_is_assoc($arrData['options']));
			$blnUseReference = isset($arrData['reference']);

			if ($arrData['eval']['includeBlankOption'] && !$arrData['eval']['multiple'])
			{
				$strLabel = isset($arrData['eval']['blankOptionLabel']) ? $arrData['eval']['blankOptionLabel'] : '-';
				$arrNew['options'][] = array('value'=>'', 'label'=>$strLabel);
			}

			foreach ($arrData['options'] as $k=>$v)
			{
				if (!is_array($v))
				{
					$arrNew['options'][] = array('value'=>($blnIsAssociative ? $k : $v), 'label'=>($blnUseReference ? ((($ref = (is_array($arrData['reference'][$v]) ? $arrData['reference'][$v][0] : $arrData['reference'][$v])) != false) ? $ref : $v) : $v));
					continue;
				}

				$key = $blnUseReference ? ((($ref = (is_array($arrData['reference'][$k]) ? $arrData['reference'][$k][0] : $arrData['reference'][$k])) != false) ? $ref : $k) : $k;
				$blnIsAssoc = array_is_assoc($v);

				foreach ($v as $kk=>$vv)
				{
					$arrNew['options'][$key][] = array('value'=>($blnIsAssoc ? $kk : $vv), 'label'=>($blnUseReference ? ((($ref = (is_array($arrData['reference'][$vv]) ? $arrData['reference'][$vv][0] : $arrData['reference'][$vv])) != false) ? $ref : $vv) : $vv));
				}
			}
		}

		$arrNew['value'] = deserialize($varValue);

		// Convert timestamps
		if ($varValue != '' && ($arrData['eval']['rgxp'] == 'date' || $arrData['eval']['rgxp'] == 'time' || $arrData['eval']['rgxp'] == 'datim'))
		{
			$objDate = new \Date($varValue);
			$arrNew['value'] = $objDate->{$arrData['eval']['rgxp']};
		}

		return $arrNew;
	}


	/**
	 * Create an initial version of a record
	 * @param string
	 * @param integer
	 * @return void
	 */
	protected function createInitialVersion($strTable, $intId)
	{
		if (!$GLOBALS['TL_DCA'][$strTable]['config']['enableVersioning'])
		{
			return;
		}

		$objVersion = $this->Database->prepare("SELECT COUNT(*) AS count FROM tl_version WHERE fromTable=? AND pid=?")
									 ->limit(1)
									 ->executeUncached($strTable, $intId);

		if ($objVersion->count < 1)
		{
			$this->createNewVersion($strTable, $intId);
		}
	}


	/**
	 * Create a new version of a record
	 * @param string
	 * @param integer
	 * @return void
	 */
	protected function createNewVersion($strTable, $intId)
	{
		if (!$GLOBALS['TL_DCA'][$strTable]['config']['enableVersioning'])
		{
			return;
		}

		// Delete old versions from the database
		$this->Database->prepare("DELETE FROM tl_version WHERE tstamp<?")
					   ->execute((time() - $GLOBALS['TL_CONFIG']['versionPeriod']));

		// Get the new record
		$objRecord = $this->Database->prepare("SELECT * FROM " . $strTable . " WHERE id=?")
									->limit(1)
									->executeUncached($intId);

		if ($objRecord->numRows < 1 || $objRecord->tstamp < 1)
		{
			return;
		}

		$intVersion = 1;
		$this->import('BackendUser', 'User');

		$objVersion = $this->Database->prepare("SELECT MAX(version) AS version FROM tl_version WHERE pid=? AND fromTable=?")
									 ->executeUncached($intId, $strTable);

		if ($objVersion->version !== null)
		{
			$intVersion = $objVersion->version + 1;
		}

		$strDescription = '';

		if (isset($objRecord->title))
		{
			$strDescription = $objRecord->title;
		}
		elseif (isset($objRecord->name))
		{
			$strDescription = $objRecord->name;
		}
		elseif (isset($objRecord->headline))
		{
			$strDescription = $objRecord->headline;
		}
		elseif (isset($objRecord->selector))
		{
			$strDescription = $objRecord->selector;
		}

		$this->Database->prepare("UPDATE tl_version SET active='' WHERE pid=? AND fromTable=?")
					   ->execute($intId, $strTable);

		$this->Database->prepare("INSERT INTO tl_version (pid, tstamp, version, fromTable, username, userid, description, active, data) VALUES (?, ?, ?, ?, ?, ?, ?, 1, ?)")
					   ->execute($intId, time(), $intVersion, $strTable, $this->User->username, $this->User->id, $strDescription, serialize($objRecord->row()));
	}
	

	/**
	 * Check whether a field is unique
	 * @param string
	 * @param string
	 * @param mixed
	 * @param integer
	 * @return boolean
	 */
	protected function fieldIsUnique($strTable, $strField, $varValue, $intId=null)
	{
		$strQuery = "SELECT * FROM $strTable WHERE $strField=?";

		if ($intId !== null)
		{
			$strQuery .= " AND id!=?";
		}

		$objUnique = $this->Database->prepare($strQuery)
						  ->limit(1)
						  ->execute($varValue, $intId);

		return $objUnique->numRows ? false : true;
	}


	/**
	 * Redirect to a front end page
	 * @param integer
	 * @param mixed
	 * @param boolean
	 * @return string
	 */
	protected function redirectToFrontendPage($intPage, $varArticle=null, $blnReturn=false)
	{
		if (($intPage = intval($intPage)) <= 0)
		{
			return '';
		}

		$strDomain = $this->Environment->base;
		$objPage = $this->getPageDetails($intPage);

		if ($objPage->domain != '')
		{
			$strDomain = ($this->Environment->ssl ? 'https://' : 'http://') . $objPage->domain . TL_PATH . '/';
		}

		if ($varArticle !== null)
		{
			$varArticle = '/articles/' . $varArticle;
		}

		$strUrl = $strDomain . $this->generateFrontendUrl($objPage->row(), $varArticle, $objPage->language);

		if ($blnReturn)
		{
			return $strUrl;
		}

		$this->redirect($strUrl);
	}


	/**
	 * Return the IDs of all child records of a particular record (see #2475)
	 * @param mixed
	 * @param string
	 * @param boolean
	 * @param array
	 * @return array
	 * @author Andreas Schempp
	 */
	protected function getChildRecords($arrParentIds, $strTable, $blnSorting=false, $arrReturn=array())
	{
		if (!is_array($arrParentIds))
		{
			$arrParentIds = array($arrParentIds);
		}

		$arrParentIds = array_map('intval', $arrParentIds);
		$objChilds = $this->Database->execute("SELECT id, pid FROM " . $strTable . " WHERE pid IN(" . implode(',', $arrParentIds) . ")" . ($blnSorting ? " ORDER BY " . $this->Database->findInSet('pid', $arrParentIds) . ", sorting" : ""));

		if ($objChilds->numRows > 0)
		{
			if ($blnSorting)
			{
				$arrChilds = array();
				$arrOrdered = array();

				while ($objChilds->next())
				{
					$arrChilds[] = $objChilds->id;
					$arrOrdered[$objChilds->pid][] = $objChilds->id;
				}

				foreach (array_reverse(array_keys($arrOrdered)) as $pid)
				{
					$pos = (int) array_search($pid, $arrReturn);
					array_insert($arrReturn, $pos+1, $arrOrdered[$pid]);
				}

				$arrReturn = $this->getChildRecords($arrChilds, $strTable, $blnSorting, $arrReturn);
			}
			else
			{
				$arrChilds = $objChilds->fetchEach('id');
				$arrReturn = array_merge($arrChilds, $this->getChildRecords($arrChilds, $strTable, $blnSorting));
			}
		}

		return $arrReturn;
	}


	/**
	 * Return the IDs of all parent records of a particular record
	 * @param integer
	 * @param string
	 * @return array
	 */
	protected function getParentRecords($intId, $strTable)
	{
		$arrReturn = array();

		// Currently supports a nesting-level of 10
		$objPages = $this->Database->prepare("SELECT id, @pid:=pid FROM $strTable WHERE id=?" . str_repeat(" UNION SELECT id, @pid:=pid FROM $strTable WHERE id=@pid", 9))
								   ->execute($intId);

		while ($objPages->next())
		{
			$arrReturn[] = $objPages->id;
		}

		return $arrReturn;
	}


	/**
	 * Get the parent records of an entry and return them as string
	 * which can be used in a log message
	 * @param string
	 * @param integer
	 * @return string
	 */
	protected function getParentEntries($strTable, $intId)
	{
		// No parent table
		if (!isset($GLOBALS['TL_DCA'][$strTable]['config']['ptable']))
		{
			return '';
		}

		$arrParent = array();

		do
		{
			// Get the pid
			$objParent = $this->Database->prepare("SELECT pid FROM " . $strTable . " WHERE id=?")
										->limit(1)
										->execute($intId);

			if ($objParent->numRows < 1)
			{
				break;
			}

			// Store the parent table information
			$strTable = $GLOBALS['TL_DCA'][$strTable]['config']['ptable'];
			$intId = $objParent->pid;

			// Add the log entry
			$arrParent[] = $strTable .'.id=' . $intId;

			// Load the data container of the parent table
			$this->loadDataContainer($strTable);
		}
		while ($intId && isset($GLOBALS['TL_DCA'][$strTable]['config']['ptable']));

		if (empty($arrParent))
		{
			return '';
		}

		return ' (parent records: ' . implode(', ', $arrParent) . ')';
	}


	/**
	 * Return true if a class exists (tries to autoload the class)
	 * @param string
	 * @param boolean
	 * @return boolean
	 */
	protected function classFileExists($strClass)
	{
		return class_exists($strClass, true);
	}


	/**
	 * Take an array of paths and eliminate nested paths
	 * @param array
	 * @return array
	 */
	protected function eliminateNestedPaths($arrPaths)
	{
		if (!is_array($arrPaths) || empty($arrPaths))
		{
			return array();
		}

		$nested = array();

		foreach ($arrPaths as $path)
		{
			$nested = array_merge($nested, preg_grep('/^' . preg_quote($path, '/') . '\/.+/', $arrPaths));
		}

		return array_values(array_diff($arrPaths, $nested));
	}


	/**
	 * Take an array of pages and eliminate nested pages
	 * @param array
	 * @param string
	 * @param boolean
	 * @return array
	 */
	protected function eliminateNestedPages($arrPages, $strTable=null, $blnSorting=false)
	{
		if (!is_array($arrPages) || empty($arrPages))
		{
			return array();
		}

		if (!$strTable)
		{
			$strTable = 'tl_page';
		}

		// Thanks to Andreas Schempp (see #2475 and #3423)
		$arrPages = array_intersect($this->getChildRecords(0, $strTable, $blnSorting), $arrPages);
		$arrPages = array_values(array_diff($arrPages, $this->getChildRecords($arrPages, $strTable, $blnSorting)));

		return $arrPages;
	}


	/**
	 * Return a "selected" attribute if the current option is selected
	 * @param string
	 * @param mixed
	 * @return string
	 */
	protected function optionSelected($strName, $varValue)
	{
		if ($strName == '')
		{
			return '';
		}

		$attribute = ' selected';

		if (TL_MODE == 'FE')
		{
			global $objPage;

			if ($objPage->outputFormat == 'xhtml')
			{
				$attribute = ' selected="selected"';
			}
		}

		return (is_array($varValue) ? in_array($strName, $varValue) : $strName == $varValue) ? $attribute : '';
	}


	/**
	 * Return a "checked" attribute if the current option is checked
	 * @param string
	 * @param mixed
	 * @return string
	 */
	protected function optionChecked($strName, $varValue)
	{
		$attribute = ' checked';

		if (TL_MODE == 'FE')
		{
			global $objPage;

			if ($objPage->outputFormat == 'xhtml')
			{
				$attribute = ' checked="checked"';
			}
		}

		return (is_array($varValue) ? in_array($strName, $varValue) : $strName == $varValue) ? $attribute : '';
	}


	/**
	 * Find a content element in the TL_CTE array and return its value
	 * @param string
	 * @return string
	 */
	protected function findContentElement($strName)
	{
		foreach ($GLOBALS['TL_CTE'] as $v)
		{
			foreach ($v as $kk=>$vv)
			{
				if ($kk == $strName)
				{
					return $vv;
				}
			}
		}

		return '';
	}


	/**
	 * Find a front end module in the FE_MOD array and return its value
	 * @param string
	 * @return string
	 */
	protected function findFrontendModule($strName)
	{
		foreach ($GLOBALS['FE_MOD'] as $v)
		{
			foreach ($v as $kk=>$vv)
			{
				if ($kk == $strName)
				{
					return $vv;
				}
			}
		}

		return '';
	}


	/**
	 * Remove old XML files from the root directory
	 * @param boolean
	 * @return array
	 */
	protected function removeOldFeeds($blnReturn=false)
	{
		$arrFeeds = array();

		// XML sitemaps
		$objFeeds = $this->Database->execute("SELECT sitemapName FROM tl_page WHERE type='root' AND createSitemap=1 AND sitemapName!=''");

		while ($objFeeds->next())
		{
			$arrFeeds[] = $objFeeds->sitemapName;
		}

		// HOOK: preserve third party feeds
		if (isset($GLOBALS['TL_HOOKS']['removeOldFeeds']) && is_array($GLOBALS['TL_HOOKS']['removeOldFeeds']))
		{
			foreach ($GLOBALS['TL_HOOKS']['removeOldFeeds'] as $callback)
			{
				$this->import($callback[0]);
				$arrFeeds = array_merge($arrFeeds, $this->$callback[0]->$callback[1]());
			}
		}

		// Make sure the dcaconfig.php is loaded
		@include TL_ROOT . '/system/config/dcaconfig.php';

		// Add the root files
		if (is_array($GLOBALS['TL_CONFIG']['rootFiles']))
		{
			foreach ($GLOBALS['TL_CONFIG']['rootFiles'] as $strFile)
			{
				$arrFeeds[] = str_replace('.xml', '', $strFile);
			}
		}

		// Delete the old files
		if (!$blnReturn)
		{
			foreach (scan(TL_ROOT) as $file)
			{
				if (is_dir(TL_ROOT . '/' . $file))
				{
					continue;
				}

				$objFile = new \File($file);

				if ($objFile->extension == 'xml' && !in_array($objFile->filename, $arrFeeds) && !preg_match('/^sitemap/i', $objFile->filename))
				{
					$objFile->delete();
				}

				$objFile->close();
			}
		}

		return $arrFeeds;
	}


	/**
	 * Add an image to a template
	 * @param object
	 * @param array
	 * @param integer
	 * @param string
	 * @return void
	 */
	protected function addImageToTemplate($objTemplate, $arrItem, $intMaxWidth=null, $strLightboxId=null)
	{
		global $objPage;

		$size = deserialize($arrItem['size']);
		$imgSize = getimagesize(TL_ROOT .'/'. $arrItem['singleSRC']);

		if ($intMaxWidth === null)
		{
			$intMaxWidth = (TL_MODE == 'BE') ? 320 : $GLOBALS['TL_CONFIG']['maxImageWidth'];
		}

		// Provide an ID for single lightbox images in HTML5 (see #3742)
		if ($strLightboxId === null && $arrItem['fullsize'])
		{
			if ($objPage->outputFormat == 'xhtml')
			{
				$strLightboxId = 'lightbox';
			}
			else
			{
				$strLightboxId = 'lightbox[' . substr(md5($objTemplate->getName() . '_' . $arrItem['id']), 0, 6) . ']';
			}
		}

		// Store the original dimensions
		$objTemplate->width = $imgSize[0];
		$objTemplate->height = $imgSize[1];

		// Adjust the image size
		if ($intMaxWidth > 0 && ($size[0] > $intMaxWidth || (!$size[0] && !$size[1] && $imgSize[0] > $intMaxWidth)))
		{
			$arrMargin = deserialize($arrItem['imagemargin']);

			// Subtract margins
			if (is_array($arrMargin) && $arrMargin['unit'] == 'px')
			{
				$intMaxWidth = $intMaxWidth - $arrMargin['left'] - $arrMargin['right'];
			}

			// See #2268 (thanks to Thyon)
			$ratio = ($size[0] && $size[1]) ? $size[1] / $size[0] : $imgSize[1] / $imgSize[0];

			$size[0] = $intMaxWidth;
			$size[1] = floor($intMaxWidth * $ratio);
		}

		$src = $this->getImage($arrItem['singleSRC'], $size[0], $size[1], $size[2]);

		// Image dimensions
		if (($imgSize = @getimagesize(TL_ROOT .'/'. rawurldecode($src))) !== false)
		{
			$objTemplate->arrSize = $imgSize;
			$objTemplate->imgSize = ' ' . $imgSize[3];
		}

		// Float image
		if ($arrItem['floating'] != '')
		{
			$objTemplate->floatClass = ' float_' . $arrItem['floating'];
			$objTemplate->float = ' float:' . $arrItem['floating'] . ';';
		}

		// Image link
		if ($arrItem['imageUrl'] != '' && TL_MODE == 'FE')
		{
			$objTemplate->href = $arrItem['imageUrl'];
			$objTemplate->attributes = '';

			if ($arrItem['fullsize'])
			{
				if (preg_match('/\.(jpe?g|gif|png)$/', $arrItem['imageUrl']))
				{
					$objTemplate->href = TL_FILES_URL . $this->urlEncode($arrItem['imageUrl']);
					$objTemplate->attributes = ($objPage->outputFormat == 'xhtml') ? ' rel="' . $strLightboxId . '"' : ' data-lightbox="' . substr($strLightboxId, 9, -1) . '"';
				}
				else
				{
					$objTemplate->attributes = ($objPage->outputFormat == 'xhtml') ? ' onclick="window.open(this.href);return false"' : ' target="_blank"';
				}
			}
		}

		// Fullsize view
		elseif ($arrItem['fullsize'] && TL_MODE == 'FE')
		{
			$objTemplate->href = TL_FILES_URL . $this->urlEncode($arrItem['singleSRC']);
			$objTemplate->attributes = ($objPage->outputFormat == 'xhtml') ? ' rel="' . $strLightboxId . '"' : ' data-lightbox="' . substr($strLightboxId, 9, -1) . '"';
		}

		// Do not urlEncode() here because getImage() already does (see #3817)
		$objTemplate->src = TL_FILES_URL . $src;
		$objTemplate->alt = specialchars($arrItem['alt']);
		$objTemplate->title = specialchars($arrItem['title']);
		$objTemplate->linkTitle = $objTemplate->title;
		$objTemplate->fullsize = $arrItem['fullsize'] ? true : false;
		$objTemplate->addBefore = ($arrItem['floating'] != 'below');
		$objTemplate->margin = $this->generateMargin(deserialize($arrItem['imagemargin']), 'padding');
		$objTemplate->caption = $arrItem['caption'];
		$objTemplate->addImage = true;
	}


	/**
	 * Add enclosures to a template
	 * @param object
	 * @param array
	 * @return void
	 */
	protected function addEnclosuresToTemplate($objTemplate, $arrItem)
	{
		$arrEnclosures = deserialize($arrItem['enclosure']);

		if (!is_array($arrEnclosures) || empty($arrEnclosures))
		{
			return;
		}

		// Check for version 3 format
		if (!is_numeric($arrEnclosures[0]))
		{
			foreach (array('details', 'answer', 'text') as $key)
			{
				if (isset($objTemplate->$key))
				{
					$objTemplate->$key = '<p class="error">'.$GLOBALS['TL_LANG']['ERR']['version2format'].'</p>';
				}
			}

			return;
		}

		$objFiles = \FilesModel::findMultipleByIds($arrEnclosures);

		if ($objFiles === null)
		{
			return;
		}

		$file = $this->Input->get('file', true);

		// Send the file to the browser
		if ($file != '')
		{
			while ($objFiles->next())
			{
				if ($file == $objFiles->path)
				{
					$this->sendFileToBrowser($file);
				}
			}

			// Do not index or cache the page
			global $objPage;
			$objPage->noSearch = 1;
			$objPage->cache = 0;

			// Send a 404 header
			header('HTTP/1.1 404 Not Found');

			foreach (array('details', 'answer', 'text') as $key)
			{
				if (isset($objTemplate->$key))
				{
					$objTemplate->$key = '<p class="error">' . sprintf($GLOBALS['TL_LANG']['ERR']['download'], $file) . '</p>';
				}
			}
		}

		$arrEnclosures = array();
		$allowedDownload = trimsplit(',', strtolower($GLOBALS['TL_CONFIG']['allowedDownload']));

		// Add download links
		while ($objFiles->next())
		{
			if ($objFiles->type == 'file')
			{				
				if (!in_array($objFiles->extension, $allowedDownload))
				{
					continue;
				}

				$objFile = new \File($objFiles->path);

				$arrEnclosures[] = array
				(
					'link'      => $objFiles->name,
					'filesize'  => $this->getReadableSize($objFile->filesize),
					'title'     => ucfirst(str_replace('_', ' ', $objFile->filename)),
					'href'      => $this->Environment->request . (($GLOBALS['TL_CONFIG']['disableAlias'] || strpos($this->Environment->request, '?') !== false) ? '&amp;' : '?') . 'file=' . $this->urlEncode($objFiles->path),
					'enclosure' => $objFiles->path,
					'icon'      => TL_FILES_URL . 'system/themes/' . $this->getTheme() . '/images/' . $objFile->icon,
					'mime'      => $objFile->mime
				);
			}
		}

		$objTemplate->enclosure = $arrEnclosures;
	}


	/**
	 * Set a static URL constant and replace the protocol when requested via SSL
	 * @param string
	 * @param string
	 * @return void
	 */
	protected function setStaticUrl($name, $url)
	{
		if (defined($name))
		{
			return;
		}

		if ($url == '' || $GLOBALS['TL_CONFIG']['debugMode'])
		{
			define($name, '');
		}
		else
		{
			if ($this->Environment->ssl)
			{
				$url = str_replace('http://', 'https://', $url);
			}

			define($name, $url . TL_PATH . '/');
		}
	}


	/**
	 * Add a static URL to a script
	 * @param string
	 * @return string
	 */
	protected function addStaticUrlTo($script)
	{
		// The feature is not used
		if (TL_PLUGINS_URL == '' && TL_SCRIPT_URL == '')
		{
			return $script;
		}

		// Absolut URLs
		if (preg_match('@^https?://@', $script))
		{
			return $script;
		}

		// Prepend the static URL
		if (strncmp($script, 'plugins/', 8) === 0)
		{
			return TL_PLUGINS_URL . $script;
		}
		else
		{
			return TL_SCRIPT_URL . $script;
		}
	}
}

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
 * @package    Backend
 * @license    LGPL
 */


/**
 * Initialize the system
 */
define('TL_MODE', 'BE');
require_once '../system/initialize.php';


/**
 * Show error messages
 */
@ini_set('display_errors', 1);
@error_reporting(E_ALL|E_STRICT);


/**
 * Class InstallTool
 *
 * Back end install tool.
 * @copyright  Leo Feyer 2005-2012
 * @author     Leo Feyer <http://www.contao.org>
 * @package    Controller
 */
class InstallTool extends Backend
{

	/**
	 * Initialize the controller
	 */
	public function __construct()
	{
		$this->import('String');
		$this->import('Config');
		$this->import('Input');
		$this->import('Environment');
		$this->import('Session');

		$GLOBALS['TL_CONFIG']['showHelp'] = false;
		$GLOBALS['TL_CONFIG']['displayErrors'] = true;

		// Static URLs
		$this->setStaticUrl('TL_FILES_URL', $GLOBALS['TL_CONFIG']['staticFiles']);
		$this->setStaticUrl('TL_SCRIPT_URL', $GLOBALS['TL_CONFIG']['staticSystem']);
		$this->setStaticUrl('TL_PLUGINS_URL', $GLOBALS['TL_CONFIG']['staticPlugins']);

		$this->loadLanguageFile('default');
		$this->loadLanguageFile('tl_install');
	}


	/**
	 * Run the controller and parse the login template
	 * @return void
	 */
	public function run()
	{
		$this->Template = new BackendTemplate('be_install');

		// Lock the tool if there are too many login attempts
		if ($GLOBALS['TL_CONFIG']['installCount'] >= 3)
		{
			$this->Template->locked = true;
			$this->outputAndExit();
		}

		// Store the FTP login credentials
		if ($this->Input->post('FORM_SUBMIT') == 'tl_ftp')
		{
			$this->storeFtpCredentials();
		}

		// Import the Files object AFTER storing the FTP settings
		$this->import('Files');

		// If the files are not writeable, the SMH is required
		if (!$this->Files->is_writeable('system/config/default.php'))
		{
			$this->outputAndExit();
		}

		$this->Template->lcfWriteable = true;

		// Create the local configuration files if not done yet
		if (!$GLOBALS['TL_CONFIG']['useFTP'])
		{
			$this->createLocalConfigurationFiles();
		}

		// Set the website path
		if ($GLOBALS['TL_CONFIG']['websitePath'] !== null && !preg_match('/^' . preg_quote(TL_PATH, '/') . '\/contao\/' . preg_quote(basename(__FILE__), '/') . '/', $this->Environment->requestUri))
		{
			$this->Config->delete("\$GLOBALS['TL_CONFIG']['websitePath']");
			$this->reload();
		}

		// Store the license acception
		if ($this->Input->post('FORM_SUBMIT') == 'tl_license')
		{
			$this->Config->update("\$GLOBALS['TL_CONFIG']['licenseAccepted']", true);
			$this->reload();
		}

		// Show the license text
		if (!$GLOBALS['TL_CONFIG']['licenseAccepted'])
		{
			$this->Template->license = true;
			$this->outputAndExit();
		}

		// Authenticate the user
		if ($this->Input->post('FORM_SUBMIT') == 'tl_login')
		{
			$this->authenticateUser();
		}

		// Auto-login on fresh installations
		if ($GLOBALS['TL_CONFIG']['installPassword'] == '4d19f112e30930cbe278de966e9b2d907568d1c8')
		{
			$this->setAuthCookie();
		}
		// Login required
		elseif (!$this->Input->cookie('TL_INSTALL_AUTH') || $_SESSION['TL_INSTALL_AUTH'] == '' || $this->Input->cookie('TL_INSTALL_AUTH') != $_SESSION['TL_INSTALL_AUTH'] || $_SESSION['TL_INSTALL_EXPIRE'] < time())
		{
			$this->Template->login = true;
			$this->outputAndExit();
		}
		// Authenticated, so renew the cookie
		else
		{
			$this->setAuthCookie();
		}

		// Store the install tool password
		if ($this->Input->post('FORM_SUBMIT') == 'tl_install')
		{
			$this->storeInstallToolPassword();
		}

		list($strPassword, $strSalt) = explode(':', $GLOBALS['TL_CONFIG']['installPassword']);

		// The password must not be "contao" or "typolight"
		if ($strPassword == sha1($strSalt . 'contao') || $strPassword == sha1($strSalt . 'typolight'))
		{
			$this->Template->setPassword = true;
			$this->outputAndExit();
		}

		// Save the encryption key
		if ($this->Input->post('FORM_SUBMIT') == 'tl_encryption')
		{
			$this->Config->update("\$GLOBALS['TL_CONFIG']['encryptionKey']", $this->Input->post('key'));
			$this->reload();
		}

		// Autogenerate an encryption key
		if ($GLOBALS['TL_CONFIG']['encryptionKey'] == '')
		{
			$strKey = md5(uniqid(mt_rand(), true));
			$this->Config->update("\$GLOBALS['TL_CONFIG']['encryptionKey']", $strKey);
			$GLOBALS['TL_CONFIG']['encryptionKey'] = $strKey;
		}

		$this->Template->encryptionKey = $GLOBALS['TL_CONFIG']['encryptionKey'];

		// Check the minimum length of the encryption key
		if (utf8_strlen($GLOBALS['TL_CONFIG']['encryptionKey']) < 12)
		{
			$this->Template->encryptionLength = true;
			$this->outputAndExit();
		}

		// Set up the database connection
		$this->setUpDatabaseConnection();

		// Run the version-specific database updates
		foreach (get_class_methods($this) as $method)
		{
			if (strncmp($method, 'update', 6) === 0)
			{
				$this->$method();
			}
		}

		// Store the collation
		$this->storeCollation();

		// Update the database tables
		if ($this->Input->post('FORM_SUBMIT') == 'tl_tables')
		{
			$sql = deserialize($this->Input->post('sql'));

			if (is_array($sql))
			{
				foreach ($sql as $key)
				{
					if (isset($_SESSION['sql_commands'][$key]))
					{
						$this->Database->query(str_replace('DEFAULT CHARSET=utf8;', 'DEFAULT CHARSET=utf8 COLLATE ' . $GLOBALS['TL_CONFIG']['dbCollation'] . ';', $_SESSION['sql_commands'][$key]));
					}
				}
			}

			$_SESSION['sql_commands'] = array();
			$this->reload();
		}
		// Clear the internal cache
		else
		{
			foreach (array('dca', 'language', 'sql') as $folder)
			{
				$objFolder = new \Folder('system/cache/' . $folder);
				$objFolder->delete();
			}
		}

		$this->handleRunOnce();
		$this->import('DbInstaller');

		$this->Template->dbUpdate = $this->DbInstaller->generateSqlForm();
		$this->Template->dbUpToDate = ($this->Template->dbUpdate != '') ? false : true;

		// Import the example website
		$this->importExampleWebsite();

		// Create an admin user
		$this->createAdminUser();

		// Clear the cron timestamps so the jobs are run
		$this->Config->delete("\$GLOBALS['TL_CONFIG']['cron_hourly']");
		$this->Config->delete("\$GLOBALS['TL_CONFIG']['cron_daily']");
		$this->Config->delete("\$GLOBALS['TL_CONFIG']['cron_weekly']");

		$this->outputAndExit();
	}


	/**
	 * Store the FTP login credentials
	 * @return void
	 */
	protected function storeFtpCredentials()
	{
		$GLOBALS['TL_CONFIG']['useFTP']  = true;
		$GLOBALS['TL_CONFIG']['ftpHost'] = $this->Input->post('host');
		$GLOBALS['TL_CONFIG']['ftpPath'] = $this->Input->post('path');
		$GLOBALS['TL_CONFIG']['ftpUser'] = $this->Input->post('username', true);

		if ($this->Input->post('password', true) != '*****')
		{
			$GLOBALS['TL_CONFIG']['ftpPass'] = $this->Input->post('password', true);
		}

		$GLOBALS['TL_CONFIG']['ftpSSL']  = $this->Input->post('ssl');
		$GLOBALS['TL_CONFIG']['ftpPort'] = (float) $this->Input->post('port');

		// Add a trailing slash
		if ($GLOBALS['TL_CONFIG']['ftpPath'] != '' && substr($GLOBALS['TL_CONFIG']['ftpPath'], -1) != '/')
		{
			$GLOBALS['TL_CONFIG']['ftpPath'] .= '/';
		}

		// Re-insert the data into the form
		$this->Template->ftpHost = $GLOBALS['TL_CONFIG']['ftpHost'];
		$this->Template->ftpPath = $GLOBALS['TL_CONFIG']['ftpPath'];
		$this->Template->ftpUser = $GLOBALS['TL_CONFIG']['ftpUser'];
		$this->Template->ftpPass = ($GLOBALS['TL_CONFIG']['ftpPass'] != '') ? '*****' : '';
		$this->Template->ftpSSL  = $GLOBALS['TL_CONFIG']['ftpSSL'];
		$this->Template->ftpPort = $GLOBALS['TL_CONFIG']['ftpPort'];

		$ftp_connect = ($GLOBALS['TL_CONFIG']['ftpSSL'] && function_exists('ftp_ssl_connect')) ? 'ftp_ssl_connect' : 'ftp_connect';

		// Try to connect and locate the Contao directory
		if (($resFtp = $ftp_connect($GLOBALS['TL_CONFIG']['ftpHost'], $GLOBALS['TL_CONFIG']['ftpPort'], 5)) == false)
		{
			$this->Template->ftpHostError = true;
			$this->outputAndExit();
		}
		elseif (!ftp_login($resFtp, $GLOBALS['TL_CONFIG']['ftpUser'], $GLOBALS['TL_CONFIG']['ftpPass']))
		{
			$this->Template->ftpUserError = true;
			$this->outputAndExit();
		}
		elseif (ftp_size($resFtp, $GLOBALS['TL_CONFIG']['ftpPath'] . 'assets/contao/framework.css') == -1)
		{
			$this->Template->ftpPathError = true;
			$this->outputAndExit();
		}

		// Update the local configuration file
		else
		{
			$this->import('Files');

			// The system/tmp folder must be writable for fopen()
			if (!is_writable(TL_ROOT . '/system/tmp'))
			{
				$this->Files->chmod('system/tmp', 0777);
			}

			// The assets/images folder must be writable for image*()
			if (!is_writable(TL_ROOT . '/assets/images'))
			{
				$this->Files->chmod('assets/images', 0777);
			}

			$folders = array('0','1','2','3','4','5','6','7','8','9','a','b','c','d','e','f');

			foreach ($folders as $folder)
			{
				if (!is_writable(TL_ROOT . '/assets/images/' . $folder))
				{
					$this->Files->chmod('assets/images/' . $folder, 0777);
				}
			}

			// The system/logs folder must be writable for error_log()
			if (!is_writable(TL_ROOT . '/system/logs'))
			{
				$this->Files->chmod('system/logs', 0777);
			}

			// Create the local configuration files
			$this->createLocalConfigurationFiles();

			// Save the FTP credentials
			$this->Config->update("\$GLOBALS['TL_CONFIG']['useFTP']", true);
			$this->Config->update("\$GLOBALS['TL_CONFIG']['ftpHost']", $GLOBALS['TL_CONFIG']['ftpHost']);
			$this->Config->update("\$GLOBALS['TL_CONFIG']['ftpPath']", $GLOBALS['TL_CONFIG']['ftpPath']);
			$this->Config->update("\$GLOBALS['TL_CONFIG']['ftpUser']", $GLOBALS['TL_CONFIG']['ftpUser']);

			if ($this->Input->post('password', true) != '*****')
			{
				$this->Config->update("\$GLOBALS['TL_CONFIG']['ftpPass']", $GLOBALS['TL_CONFIG']['ftpPass']);
			}

			$this->Config->update("\$GLOBALS['TL_CONFIG']['ftpSSL']",  $GLOBALS['TL_CONFIG']['ftpSSL']);
			$this->Config->update("\$GLOBALS['TL_CONFIG']['ftpPort']", $GLOBALS['TL_CONFIG']['ftpPort']);

			$this->reload();
		}
	}


	/**
	 * Authenticate the user
	 * @return void
	 */
	protected function authenticateUser()
	{
		$_SESSION['TL_INSTALL_AUTH'] = '';
		$_SESSION['TL_INSTALL_EXPIRE'] = 0;

		list($strPassword, $strSalt) = explode(':', $GLOBALS['TL_CONFIG']['installPassword']);

		// Password is correct but not yet salted
		if ($strSalt == '' && $strPassword == sha1($this->Input->post('password')))
		{
			$strSalt = substr(md5(uniqid(mt_rand(), true)), 0, 23);
			$strPassword = sha1($strSalt . $this->Input->post('password'));
			$this->Config->update("\$GLOBALS['TL_CONFIG']['installPassword']", $strPassword . ':' . $strSalt);
		}

		// Set the cookie
		if ($strSalt != '' && $strPassword == sha1($strSalt . $this->Input->post('password')))
		{
			$this->setAuthCookie();
			$this->Config->update("\$GLOBALS['TL_CONFIG']['installCount']", 0);
			$this->reload();
		}

		// Increase the login count
		$this->Config->update("\$GLOBALS['TL_CONFIG']['installCount']", $GLOBALS['TL_CONFIG']['installCount'] + 1);
		$this->Template->passwordError = $GLOBALS['TL_LANG']['ERR']['invalidPass'];
	}


	/**
	 * Store the install tool password
	 * @return void
	 */
	protected function storeInstallToolPassword()
	{
		$strPassword = $this->Input->post('password', true);

		// Do not allow special characters
		if (preg_match('/[#\(\)\/<=>]/', $strPassword))
		{
			$this->Template->passwordError = $GLOBALS['TL_LANG']['ERR']['extnd'];
		}

		// The passwords do not match
		elseif ($strPassword != $this->Input->post('confirm_password', true))
		{
			$this->Template->passwordError = $GLOBALS['TL_LANG']['ERR']['passwordMatch'];
		}

		// The password is too short
		elseif (utf8_strlen($strPassword) < $GLOBALS['TL_CONFIG']['minPasswordLength'])
		{
			$this->Template->passwordError = sprintf($GLOBALS['TL_LANG']['ERR']['passwordLength'], $GLOBALS['TL_CONFIG']['minPasswordLength']);
		}

		// Save the password
		else
		{
			$strSalt = substr(md5(uniqid(mt_rand(), true)), 0, 23);
			$strPassword = sha1($strSalt . $strPassword);
			$this->Config->update("\$GLOBALS['TL_CONFIG']['installPassword']", $strPassword . ':' . $strSalt);

			$this->reload();
		}
	}


	/**
	 * Set up the database connection
	 * @return void
	 */
	protected function setUpDatabaseConnection()
	{
		$strDrivers = '';
		$arrDrivers = array();

		if (function_exists('mysql_connect'))
		{
			$arrDrivers[] = 'MySQL';
		}
		if (class_exists('mysqli', false))
		{
			$arrDrivers[] = 'MySQLi';
		}
		if (function_exists('oci_connect'))
		{
			$arrDrivers[] = 'Oracle';
		}
		if (function_exists('mssql_connect'))
		{
			$arrDrivers[] = 'MSSQL';
		}
		if (function_exists('pg_connect'))
		{
			$arrDrivers[] = 'PostgreSQL';
		}
		if (function_exists('sybase_connect'))
		{
			$arrDrivers[] = 'Sybase';
		}

		foreach ($arrDrivers as $strDriver)
		{
			$strDrivers .= sprintf('<option value="%s"%s>%s</option>',
									$strDriver,
									(($strDriver == $GLOBALS['TL_CONFIG']['dbDriver']) ? ' selected="selected"' : ''),
									$strDriver);
		}

		$this->Template->drivers = $strDrivers;
		$this->Template->driver = $GLOBALS['TL_CONFIG']['dbDriver'];
		$this->Template->host = $GLOBALS['TL_CONFIG']['dbHost'];
		$this->Template->user = $GLOBALS['TL_CONFIG']['dbUser'];
		$this->Template->pass = ($GLOBALS['TL_CONFIG']['dbPass'] != '') ? '*****' : '';
		$this->Template->port = $GLOBALS['TL_CONFIG']['dbPort'];
		$this->Template->pconnect = $GLOBALS['TL_CONFIG']['dbPconnect'];
		$this->Template->dbcharset = $GLOBALS['TL_CONFIG']['dbCharset'];
		$this->Template->database = $GLOBALS['TL_CONFIG']['dbDatabase'];

		// Store the database connection parameters
		if ($this->Input->post('FORM_SUBMIT') == 'tl_database_login')
		{
			foreach (preg_grep('/^db/', array_keys($_POST)) as $strKey)
			{
				if ($strKey == 'dbPass' && $this->Input->post($strKey, true) == '*****')
				{
					continue;
				}

				$this->Config->update("\$GLOBALS['TL_CONFIG']['$strKey']", $this->Input->post($strKey, true));
			}

			$this->reload();
		}

		// Try to connect
		try
		{
			$this->import('Database');
			$this->Database->listTables();
			$this->Template->dbConnection = true;
		}
		catch (Exception $e)
		{
			$this->Template->dbConnection = false;
			$this->Template->dbError = $e->getMessage();
			$this->outputAndExit();
		}
	}


	/**
	 * Store the collation
	 * @return void
	 */
	protected function storeCollation()
	{
		if ($this->Input->post('FORM_SUBMIT') == 'tl_collation')
		{
			$arrTables = array();
			$strCharset = strtolower($GLOBALS['TL_CONFIG']['dbCharset']);
			$strCollation = $this->Input->post('dbCollation');

			try
			{
				$this->Database->query("ALTER DATABASE {$GLOBALS['TL_CONFIG']['dbDatabase']} DEFAULT CHARACTER SET $strCharset COLLATE $strCollation");
			}
			catch (Exception $e) {}

			$objField = $this->Database->prepare("SELECT * FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME LIKE 'tl_%' AND !ISNULL(COLLATION_NAME)")
									   ->execute($GLOBALS['TL_CONFIG']['dbDatabase']);

			while ($objField->next())
			{
				if (!in_array($objField->TABLE_NAME, $arrTables))
				{
					$this->Database->query("ALTER TABLE {$objField->TABLE_NAME} DEFAULT CHARACTER SET $strCharset COLLATE $strCollation");
					$arrTables[] = $objField->TABLE_NAME;
				}

				$strQuery = "ALTER TABLE {$objField->TABLE_NAME} CHANGE {$objField->COLUMN_NAME} {$objField->COLUMN_NAME} {$objField->COLUMN_TYPE} CHARACTER SET $strCharset COLLATE $strCollation";

				if ($objField->IS_NULLABLE == 'YES')
				{
					$strQuery .= " NULL";
				}
				else
				{
					$strQuery .= " NOT NULL DEFAULT '{$objField->COLUMN_DEFAULT}'";
				}

				$this->Database->query($strQuery);
			}

			$this->Config->update("\$GLOBALS['TL_CONFIG']['dbCollation']", $strCollation);
			$this->reload();
		}

		$arrOptions = array();

		$objCollation = $this->Database->prepare("SHOW COLLATION LIKE ?")
									   ->execute($GLOBALS['TL_CONFIG']['dbCharset'] .'%');

		while ($objCollation->next())
		{
			$key = $objCollation->Collation;

			$arrOptions[$key] = sprintf('<option value="%s"%s>%s</option>',
										$key,
										(($key == $GLOBALS['TL_CONFIG']['dbCollation']) ? ' selected="selected"' : ''),
										$key);
		}

		ksort($arrOptions);
		$this->Template->collations = implode('', $arrOptions);
	}


	/**
	 * Import the example website
	 * @return void
	 */
	protected function importExampleWebsite()
	{
		if ($this->Input->post('FORM_SUBMIT') == 'tl_tutorial')
		{
			$this->Template->emptySelection = true;
			$strTemplate = basename($this->Input->post('template'));

			// Template selected
			if ($strTemplate != '' && file_exists(TL_ROOT . '/templates/' . $strTemplate))
			{
				$this->Config->update("\$GLOBALS['TL_CONFIG']['exampleWebsite']", time());
				$tables = preg_grep('/^tl_/i', $this->Database->listTables());

				// Truncate tables
				if (!isset($_POST['preserve']))
				{
					foreach ($tables as $table)
					{
						$this->Database->execute("TRUNCATE TABLE " . $table);
					}
				}

				// Import data
				$file = file(TL_ROOT . '/templates/' . $strTemplate);
				$sql = preg_grep('/^INSERT /', $file);

				foreach ($sql as $query)
				{
					$this->Database->execute($query);
				}

				$this->reload();
			}
		}

		$strTemplates = '<option value="">-</option>';

		foreach (scan(TL_ROOT . '/templates') as $strFile)
		{
			if (preg_match('/.sql$/', $strFile))
			{
				$strTemplates .= sprintf('<option value="%s">%s</option>', $strFile, specialchars($strFile));
			}
		}

		$this->Template->templates = $strTemplates;
		$this->Template->dateImported = $this->parseDate($GLOBALS['TL_CONFIG']['datimFormat'], $GLOBALS['TL_CONFIG']['exampleWebsite']);
	}


	/**
	 * Create an admin user
	 * @return void
	 */
	protected function createAdminUser()
	{
		try
		{
			$objAdmin = $this->Database->execute("SELECT COUNT(*) AS count FROM tl_user WHERE admin=1");

			if ($objAdmin->count > 0)
			{
				$this->Template->adminCreated = true;
			}
			// Create an admin account
			elseif ($this->Input->post('FORM_SUBMIT') == 'tl_admin')
			{
				// Do not allow special characters in usernames
				if (preg_match('/[#\(\)\/<=>]/', html_entity_decode($this->Input->post('username'))))
				{
					$this->Template->usernameError = $GLOBALS['TL_LANG']['ERR']['extnd'];
				}
				// The username must not contain whitespace characters (see #4006)
				elseif (strpos($this->Input->post('username'), ' ') !== false)
				{
					$this->Template->usernameError = sprintf($GLOBALS['TL_LANG']['ERR']['noSpace'], $GLOBALS['TL_LANG']['MSC']['username']);
				}
				// Do not allow special characters in passwords
				elseif (preg_match('/[#\(\)\/<=>]/', html_entity_decode($this->Input->post('pass'))))
				{
					$this->Template->passwordError = $GLOBALS['TL_LANG']['ERR']['extnd'];
				}
				// Passwords do not match
				elseif ($this->Input->post('pass') != $this->Input->post('confirm_pass'))
				{
					$this->Template->passwordError = $GLOBALS['TL_LANG']['ERR']['passwordMatch'];
				}
				// Password too short
				elseif (utf8_strlen($this->Input->post('pass')) < $GLOBALS['TL_CONFIG']['minPasswordLength'])
				{
					$this->Template->passwordError = sprintf($GLOBALS['TL_LANG']['ERR']['passwordLength'], $GLOBALS['TL_CONFIG']['minPasswordLength']);
				}
				// Password and username are the same
				elseif ($this->Input->post('pass') == $this->Input->post('username'))
				{
					$this->Template->passwordError = $GLOBALS['TL_LANG']['ERR']['passwordName'];
				}
				// Save the data
				elseif ($this->Input->post('name') != '' && $this->Input->post('email', true) != '' && $this->Input->post('username') != '')
				{
					$strSalt = substr(md5(uniqid(mt_rand(), true)), 0, 23);
					$strPassword = sha1($strSalt . $this->Input->post('pass'));
					$time = time();

					$this->Database->prepare("INSERT INTO tl_user (tstamp, name, email, username, password, admin, showHelp, useRTE, useCE, thumbnails, dateAdded) VALUES ($time, ?, ?, ?, ?, 1, 1, 1, 1, 1, $time)")
						->execute($this->Input->post('name'), $this->Input->post('email', true), $this->Input->post('username'), $strPassword . ':' . $strSalt);

					$this->Config->update("\$GLOBALS['TL_CONFIG']['adminEmail']", $this->Input->post('email', true));
					$this->reload();
				}

				$this->Template->adminName = $this->Input->post('name');
				$this->Template->adminEmail = $this->Input->post('email', true);
				$this->Template->adminUser = $this->Input->post('username');
			}
		}
		catch (Exception $e)
		{
			$this->Template->adminCreated = false;
		}
	}


	/**
	 * Create the local configuration files if they do not exist
	 * @return void
	 */
	protected function createLocalConfigurationFiles()
	{
		// The localconfig.php file is created by the Config class
		foreach (array('dcaconfig', 'initconfig', 'langconfig') as $file)
		{
			if (!file_exists(TL_ROOT . '/system/config/' . $file . '.php'))
			{
				$objFile = new File('system/config/'. $file .'.php');
				$objFile->write('<?php' . "\n\n// Put your custom configuration here\n");
				$objFile->close();
			}
		}
	}


	/**
	 * Set the authentication cookie
	 * @return void
	 */
	protected function setAuthCookie()
	{
		$_SESSION['TL_INSTALL_EXPIRE'] = (time() + 300);
		$_SESSION['TL_INSTALL_AUTH'] = md5(uniqid(mt_rand(), true) . (!$GLOBALS['TL_CONFIG']['disableIpCheck'] ? $this->Environment->ip : '') . session_id());
		$this->setCookie('TL_INSTALL_AUTH', $_SESSION['TL_INSTALL_AUTH'], $_SESSION['TL_INSTALL_EXPIRE'], $GLOBALS['TL_CONFIG']['websitePath']);
	}


	/**
	 * Output the template file and exit
	 * @return void
	 */
	protected function outputAndExit()
	{
		$this->Template->theme = $this->getTheme();
		$this->Template->base = $this->Environment->base;
		$this->Template->language = $GLOBALS['TL_LANGUAGE'];
		$this->Template->charset = $GLOBALS['TL_CONFIG']['characterSet'];
		$this->Template->pageOffset = $this->Input->cookie('BE_PAGE_OFFSET');
		$this->Template->action = ampersand($this->Environment->request);
		$this->Template->noCookies = $GLOBALS['TL_LANG']['MSC']['noCookies'];
		$this->Template->title = $GLOBALS['TL_LANG']['tl_install']['installTool'][0];
		$this->Template->expandNode = $GLOBALS['TL_LANG']['MSC']['expandNode'];
		$this->Template->collapseNode = $GLOBALS['TL_LANG']['MSC']['collapseNode'];
		$this->Template->loadingData = $GLOBALS['TL_LANG']['MSC']['loadingData'];
		$this->Template->ie6warning = sprintf($GLOBALS['TL_LANG']['ERR']['ie6warning'], '<a href="http://ie6countdown.com">', '</a>');

		$this->Template->output();
		exit;
	}


	/**
	 * Version 2.8.0 update
	 * @return void
	 */
	protected function update28()
	{
		if ($this->Database->tableExists('tl_layout') && !$this->Database->fieldExists('script', 'tl_layout'))
		{
			if ($this->Input->post('FORM_SUBMIT') == 'tl_28update')
			{
				$this->import('DbUpdater');
				$this->DbUpdater->run28Update();
				$this->reload();
			}

			$this->Template->is28Update = true;
			$this->outputAndExit();
		}
	}


	/**
	 * Version 2.9.0 update
	 * @return void
	 */
	protected function update29()
	{
		if ($this->Database->tableExists('tl_layout') && !$this->Database->tableExists('tl_theme'))
		{
			if ($this->Input->post('FORM_SUBMIT') == 'tl_29update')
			{
				$this->import('DbUpdater');
				$this->DbUpdater->run29Update();
				$this->reload();
			}

			$this->Template->is28Update = true;
			$this->outputAndExit();
		}
	}


	/**
	 * Version 2.9.2 update
	 * @return void
	 */
	protected function update292()
	{
		if ($this->Database->tableExists('tl_calendar_events'))
		{
			$arrFields = $this->Database->listFields('tl_calendar_events');

			foreach ($arrFields as $arrField)
			{
				if ($arrField['name'] == 'startDate' && $arrField['type'] != 'int')
				{
					if ($this->Input->post('FORM_SUBMIT') == 'tl_292update')
					{
						$this->import('DbUpdater');
						$this->DbUpdater->run292Update();
						$this->reload();
					}

					$this->Template->is292Update = true;
					$this->outputAndExit();
				}
			}
		}
	}


	/**
	 * Version 2.10.0 update
	 * @return void
	 */
	protected function update210()
	{
		if ($this->Database->tableExists('tl_style') && !$this->Database->fieldExists('positioning', 'tl_style'))
		{
			if ($this->Input->post('FORM_SUBMIT') == 'tl_210update')
			{
				$this->import('DbUpdater');
				$this->DbUpdater->run210Update();
				$this->reload();
			}

			$this->Template->is210Update = true;
			$this->outputAndExit();
		}
	}


	/**
	 * Version 3.0.0 update
	 * @return void
	 */
	protected function update300()
	{
		// Fresh installation
		if (!$this->Database->tableExists('tl_module'))
		{
			return;
		}

		$objRow = $this->Database->query("SELECT COUNT(*) AS count FROM tl_user");

		// Still a fresh installation
		if ($objRow->count < 1)
		{
			return;
		}

		// Step 1: database structure
		if (!$this->Database->tableExists('tl_files'))
		{
			if ($this->Input->post('FORM_SUBMIT') == 'tl_30update')
			{
				$this->import('DbUpdater');
				$this->DbUpdater->run300Update();
				$this->reload();
			}

			$this->Template->step = 1;
			$this->Template->is30Update = true;
			$this->outputAndExit();
		}

		$objRow = $this->Database->query("SELECT COUNT(*) AS count FROM tl_files");

		// Step 2: scan the upload folder
		if ($objRow->count < 1)
		{
			if ($this->Input->post('FORM_SUBMIT') == 'tl_30update')
			{
				$this->import('DbUpdater');
				$this->DbUpdater->scanUploadFolder();
				$this->Config->update("\$GLOBALS['TL_CONFIG']['checkFileTree']", true);
				$this->reload();
			}

			$this->Template->step = 2;
			$this->Template->is30Update = true;
			$this->outputAndExit();
		}

		// Step 3: update the database fields
		elseif ($GLOBALS['TL_CONFIG']['checkFileTree'])
		{
			if ($this->Input->post('FORM_SUBMIT') == 'tl_30update')
			{
				$this->import('DbUpdater');
				$this->DbUpdater->updateFileTreeFields();
				$this->Config->update("\$GLOBALS['TL_CONFIG']['checkFileTree']", false);
				$this->reload();
			}

			$this->Template->step = 3;
			$this->Template->is30Update = true;
			$this->outputAndExit();
		}
	}
}


/**
 * Instantiate the controller
 */
$objInstallTool = new InstallTool();
$objInstallTool->run();

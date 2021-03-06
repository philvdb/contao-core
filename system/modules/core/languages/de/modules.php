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
 * @package    Language
 * @license    LGPL
 */


/**
 * Back end modules
 */
$GLOBALS['TL_LANG']['MOD']['content']     = 'Inhalte';
$GLOBALS['TL_LANG']['MOD']['article']     = array('Artikel', 'Artikel und Inhaltselemente verwalten');
$GLOBALS['TL_LANG']['MOD']['form']        = array('Formulargenerator', 'Individuelle Formulare gestalten und deren Daten speichern oder versenden');
$GLOBALS['TL_LANG']['MOD']['design']      = 'Layout';
$GLOBALS['TL_LANG']['MOD']['themes']      = array('Themes', 'Frontend-Module, Stylesheets, Seitenlayouts und Templates verwalten');
$GLOBALS['TL_LANG']['MOD']['css']         = array('Stylesheets', 'Stylesheets erstellen, um die Frontend-Ausgabe zu formatieren');
$GLOBALS['TL_LANG']['MOD']['modules']     = array('Frontend-Module', 'Die Frontend-Module der Webseite verwalten');
$GLOBALS['TL_LANG']['MOD']['layout']      = array('Seitenlayouts', 'Module und Stylesheets zu einem Seitenlayout kombinieren');
$GLOBALS['TL_LANG']['MOD']['page']        = array('Seitenstruktur', 'Die Seitenstruktur der Webseite(n) erstellen');
$GLOBALS['TL_LANG']['MOD']['tpl_editor']  = array('Templates', 'Templates im Backend bearbeiten');
$GLOBALS['TL_LANG']['MOD']['accounts']    = 'Benutzerverwaltung';
$GLOBALS['TL_LANG']['MOD']['member']      = array('Mitglieder', 'Mitgliederkonten verwalten (Frontend-Benutzer)');
$GLOBALS['TL_LANG']['MOD']['mgroup']      = array('Mitgliedergruppen', 'Mitgliedergruppen verwalten (Frontend-Benutzergruppen)');
$GLOBALS['TL_LANG']['MOD']['user']        = array('Benutzer', 'Benutzerkonten verwalten (Backend-Benutzer)');
$GLOBALS['TL_LANG']['MOD']['group']       = array('Benutzergruppen', 'Benutzergruppen verwalten (Backend-Benutzergruppen)');
$GLOBALS['TL_LANG']['MOD']['system']      = 'System';
$GLOBALS['TL_LANG']['MOD']['files']       = array('Dateiverwaltung', 'Dateien und Ordner verwalten oder neue Dateien auf den Server übertragen');
$GLOBALS['TL_LANG']['MOD']['log']         = array('System-Log', 'Das System-Log durchsuchen und die Aktivität auf der Webseite analysieren');
$GLOBALS['TL_LANG']['MOD']['settings']    = array('Einstellungen', 'Die Contao-Konfiguration prüfen und optimieren');
$GLOBALS['TL_LANG']['MOD']['maintenance'] = array('Systemwartung', 'Contao warten oder aktualisieren');
$GLOBALS['TL_LANG']['MOD']['undo']        = array('Wiederherstellen', 'Gelöschte Datensätze wiederherstellen');
$GLOBALS['TL_LANG']['MOD']['login']       = array('Persönliche Daten', 'Persönliche Daten ändern oder ein neues Passwort setzen');


/**
 * Front end modules
 */
$GLOBALS['TL_LANG']['FMD']['navigationMenu'] = 'Navigation';
$GLOBALS['TL_LANG']['FMD']['navigation']     = array('Navigationsmenü', 'Erzeugt ein Navigationsmenü aus der Seitenstruktur');
$GLOBALS['TL_LANG']['FMD']['customnav']      = array('Individuelle Navigation', 'Erzeugt ein individuelles Navigationsmenü');
$GLOBALS['TL_LANG']['FMD']['breadcrumb']     = array('Navigationspfad', 'Erzeugt einen Navigationspfad');
$GLOBALS['TL_LANG']['FMD']['quicknav']       = array('Quicknavigation', 'Erzeugt ein Drop-Down-Menü aus der Seitenstruktur');
$GLOBALS['TL_LANG']['FMD']['quicklink']      = array('Quicklink', 'Erzeugt ein individuelles Drop-Down-Menü');
$GLOBALS['TL_LANG']['FMD']['booknav']        = array('Buchnavigation', 'Erzeugt ein Buchnavigationsmenü');
$GLOBALS['TL_LANG']['FMD']['articlenav']     = array('Artikelnavigation', 'Erzeugt ein Seitenumbruch-Menü zur Artikelnavigation');
$GLOBALS['TL_LANG']['FMD']['sitemap']        = array('Sitemap', 'Erzeugt eine Liste aller Seiten aus der Seitenstruktur');
$GLOBALS['TL_LANG']['FMD']['user']           = 'Benutzer';
$GLOBALS['TL_LANG']['FMD']['login']          = array('Login-Formular', 'Erzeugt ein Anmeldeformular (Login)');
$GLOBALS['TL_LANG']['FMD']['logout']         = array('Automatischer Logout', 'Meldet einen Benutzer automatisch ab (Logout)');
$GLOBALS['TL_LANG']['FMD']['personalData']   = array('Persönliche Daten', 'Erzeugt ein Formular zur Bearbeitung der Benutzerdaten');
$GLOBALS['TL_LANG']['FMD']['registration']   = array('Registrierung', 'Erzeugt ein Formular zur Benutzerregistrierung');
$GLOBALS['TL_LANG']['FMD']['lostPassword']   = array('Passwort vergessen', 'Erzeugt ein Formular zur Passwort-Anforderung');
$GLOBALS['TL_LANG']['FMD']['closeAccount']   = array('Konto schließen', 'Erzeugt ein Formular zur Löschung eines Benutzerkontos');
$GLOBALS['TL_LANG']['FMD']['application']    = 'Anwendungen';
$GLOBALS['TL_LANG']['FMD']['form']           = array('Formular', 'Fügt der Seite ein Formular hinzu');
$GLOBALS['TL_LANG']['FMD']['search']         = array('Suchmaschine', 'Fügt der Seite ein Suchformular hinzu');
$GLOBALS['TL_LANG']['FMD']['articleList']    = array('Artikelliste', 'Erzeugt eine Liste aller Artikel einer Spalte');
$GLOBALS['TL_LANG']['FMD']['miscellaneous']  = 'Verschiedenes';
$GLOBALS['TL_LANG']['FMD']['flash']          = array('Flash-Film', 'Bindet einen Flash-Film in eine Seite ein');
$GLOBALS['TL_LANG']['FMD']['randomImage']    = array('Zufallsbild', 'Fügt der Seite ein zufällig ausgewähltes Bild hinzu');
$GLOBALS['TL_LANG']['FMD']['html']           = array('Eigener HTML-Code', 'Erlaubt das Hinzufügen von eigenem HTML-Code');
$GLOBALS['TL_LANG']['FMD']['rss_reader']     = array('RSS-Reader', 'Fügt der Seite einen RSS-Feed hinzu');

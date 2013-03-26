<?php

/**
 * Contao Open Source CMS
 *
 * Copyright (c) 2005-2013 Leo Feyer
 *
 * @package Library
 * @link    https://contao.org
 * @license http://www.gnu.org/licenses/lgpl-3.0.html LGPL
 */

namespace Contao;


/**
 * Creates, reads, writes and deletes folders
 *
 * Usage:
 *
 *     $folder = new Folder('test');
 *
 *     if (!$folder->isEmpty())
 *     {
 *         $folder->purge();
 *     }
 *
 * @package   Library
 * @author    Leo Feyer <https://github.com/leofeyer>
 * @copyright Leo Feyer 2005-2013
 */
class Folder extends \System
{

	/**
	 * Folder name
	 * @var string
	 */
	protected $strFolder;

	/**
	 * Synchronize the database
	 * @var boolean
	 */
	protected $blnSyncDb = false;


	/**
	 * Check whether the folder exists
	 *
	 * @param string $strFolder The folder path
	 *
	 * @throws \Exception If $strFolder is not a folder
	 */
	public function __construct($strFolder)
	{
		// Handle open_basedir restrictions
		if ($strFolder == '.')
		{
			$strFolder = '';
		}

		// Check whether it is a directory
		if (is_file(TL_ROOT . '/' . $strFolder))
		{
			throw new \Exception(sprintf('File "%s" is not a directory', $strFolder));
		}

		$this->import('Files');
		$this->strFolder = $strFolder;

		// Check whether we need to sync the database
		$this->blnSyncDb = ($GLOBALS['TL_CONFIG']['uploadPath'] != 'templates' && strncmp($strFolder . '/', $GLOBALS['TL_CONFIG']['uploadPath'] . '/', strlen($GLOBALS['TL_CONFIG']['uploadPath']) + 1) === 0);

		// Check the excluded folders
		if ($this->blnSyncDb && $GLOBALS['TL_CONFIG']['fileSyncExclude'] != '')
		{
			$arrExempt = array_map(function($e) {
				return $GLOBALS['TL_CONFIG']['uploadPath'] . '/' . $e;
			}, trimsplit(',', $GLOBALS['TL_CONFIG']['fileSyncExclude']));

			foreach ($arrExempt as $strExempt)
			{
				if (strncmp($strExempt . '/', $strFolder . '/', strlen($strExempt) + 1) === 0)
				{
					$this->blnSyncDb = false;
					break;
				}
			}
		}

		// Create the folder if it does not exist
		if (!is_dir(TL_ROOT . '/' . $this->strFolder))
		{
			$strPath = '';
			$arrChunks = explode('/', $this->strFolder);

			// Create the folder
			foreach ($arrChunks as $strFolder)
			{
				$strPath .= ($strPath ? '/' : '') . $strFolder;
				$this->Files->mkdir($strPath);
			}

			// Update the DBAFS
			if ($this->blnSyncDb)
			{
				\Dbafs::addResource($this->strFolder);
				\Dbafs::updateFolderHashes($this->strFolder);
			}
		}
	}


	/**
	 * Return an object property
	 *
	 * Supported keys:
	 *
	 * * hash: the folder's MD5 hash
	 * * path: the path to the folder
	 * * size: the folder size
	 *
	 * @param string $strKey The property name
	 *
	 * @return mixed The property value
	 */
	public function __get($strKey)
	{
		switch ($strKey)
		{
			case 'hash':
				return $this->getHash();
				break;

			case 'name':
			case 'basename':
				return basename($this->strFolder);
				break;

			case 'path':
			case 'value':
				return $this->strFolder;
				break;

			case 'size':
				return $this->getSize();
				break;

			default:
				return parent::__get($strKey);
				break;
		}
	}


	/**
	 * Return true if the folder is empty
	 *
	 * @return boolean True if the folder is empty
	 */
	public function isEmpty()
	{
		return (count(scan(TL_ROOT . '/' . $this->strFolder)) < 1);
	}


	/**
	 * Purge the folder
	 */
	public function purge()
	{
		if (!$this->blnSyncDb)
		{
			$this->Files->rrdir($this->strFolder, true);
		}
		else
		{
			// Purge the folder
			$this->Files->rrdir($this->strFolder, true);

			// Remove the DB entries of all subfolders and files
			$objFiles = \FilesModel::findMultipleByBasepath($this->strFolder . '/');

			if ($objFiles !== null)
			{
				while ($objFiles->next())
				{
					$objFiles->delete();
				}
			}

			// Update the MD5 hash of the parent folders
			\Dbafs::updateFolderHashes($this->strFolder);
		}
	}


	/**
	 * Purge the folder
	 *
	 * @deprecated Use $this->purge() instead
	 */
	public function clear()
	{
		$this->purge();
	}


	/**
	 * Delete the folder
	 */
	public function delete()
	{
		if (!$this->blnSyncDb)
		{
			$this->Files->rrdir($this->strFolder);
		}
		else
		{
			// Remove the folder
			$this->Files->rrdir($this->strFolder);

			// Find the corresponding DB entry
			$objModel = \FilesModel::findByPath($this->strFolder, array('uncached'=>true));

			if ($objModel !== null)
			{
				$objModel->delete();
			}

			// Remove the DB entries of all subfolders and files
			$objFiles = \FilesModel::findMultipleByBasepath($this->strFolder . '/');

			if ($objFiles !== null)
			{
				while ($objFiles->next())
				{
					$objFiles->delete();
				}
			}

			// Update the MD5 hash of the parent folders
			\Dbafs::updateFolderHashes($this->strFolder);
		}
	}


	/**
	 * Set the folder permissions
	 *
	 * @param string $intChmod The CHMOD settings
	 *
	 * @return boolean True if the operation was successful
	 */
	public function chmod($intChmod)
	{
		return $this->Files->chmod($this->strFolder, $intChmod);
	}


	/**
	 * Rename the folder
	 *
	 * @param string $strNewName The new path
	 *
	 * @return boolean True if the operation was successful
	 */
	public function renameTo($strNewName)
	{
		if (!$this->blnSyncDb)
		{
			if (($return = $this->Files->rename($this->strFolder, $strNewName)) != false)
			{
				$this->strFolder = $strNewName;
			}
		}
		else
		{
			// Find the corresponding DB entry
			$objFile = \FilesModel::findByPath($this->strFolder, array('uncached'=>true));

			if ($objFile === null)
			{
				$objFile = \Dbafs::addResource($this->strFolder);
			}

			$strParent = dirname($strNewName);

			// Create the parent folder if it does not exist
			if (!is_dir(TL_ROOT . '/' . $strParent))
			{
				new \Folder($strParent);
			}

			// Set the parent ID
			if ($strParent == $GLOBALS['TL_CONFIG']['uploadPath'])
			{
				$objFile->pid = 0;
			}
			else
			{
				$objFolder = \FilesModel::findByPath($strParent, array('uncached'=>true));

				if ($objFolder === null)
				{
					$objFolder = \Dbafs::addResource($strParent);
				}

				$objFile->pid = $objFolder->id;
			}

			// Update all child records
			$objFiles = \FilesModel::findMultipleByBasepath($this->strFolder . '/');

			if ($objFiles !== null)
			{
				while ($objFiles->next())
				{
					$objFiles->path = preg_replace('@^' . $this->strFolder . '/@', $strNewName . '/', $objFiles->path);
					$objFiles->save();
				}
			}

			// Move the folder
			if (($return = $this->Files->rename($this->strFolder, $strNewName)) != false)
			{
				$this->strFolder = $strNewName;
			}

			// Update the database
			$objFile->path = $strNewName;
			$objFile->name = basename($strNewName);
			$objFile->save();

			// Update the MD5 hash of the parent folders
			if ($strParent != $GLOBALS['TL_CONFIG']['uploadPath'])
			{
				\Dbafs::updateFolderHashes($strParent);
			}
			if (($strPath = dirname($this->strFolder)) != $GLOBALS['TL_CONFIG']['uploadPath'])
			{
				\Dbafs::updateFolderHashes($strPath);
			}
		}

		return $return;
	}


	/**
	 * Copy the folder
	 *
	 * @param string $strNewName The target path
	 *
	 * @return boolean True if the operation was successful
	 */
	public function copyTo($strNewName)
	{
		if (!$this->blnSyncDb)
		{
			$return = $this->Files->rcopy($this->strFolder, $strNewName);
		}
		else
		{
			// Find the corresponding DB entry
			$objFile = \FilesModel::findByPath($this->strFolder, array('uncached'=>true));

			if ($objFile === null)
			{
				$objFile = \Dbafs::addResource($this->strFolder);
			}

			$strParent = dirname($strNewName);

			// Create the parent folder if it does not exist
			if (!is_dir(TL_ROOT . '/' . $strParent))
			{
				new \Folder($strParent);
			}

			$objNewFolder = clone $objFile->current();

			// Set the parent ID
			if ($strParent == $GLOBALS['TL_CONFIG']['uploadPath'])
			{
				$objNewFolder->pid = 0;
			}
			else
			{
				$objFolder = \FilesModel::findByPath($strParent, array('uncached'=>true));

				if ($objFolder === null)
				{
					$objFolder = \Dbafs::addResource($strParent);
				}

				$objNewFolder->pid = $objFolder->id;
			}

			// Update the database
			$objNewFolder->tstamp = time();
			$objNewFolder->path = $strNewName;
			$objNewFolder->name = basename($strNewName);
			$objNewFolder->save();

			// Update all child records
			$objFiles = \FilesModel::findMultipleByBasepath($this->strFolder . '/');

			if ($objFiles !== null)
			{
				while ($objFiles->next())
				{
					$objNewFile = clone $objFiles->current();

					$objNewFile->pid = $objNewFolder->id;
					$objNewFile->tstamp = time();
					$objNewFile->path = $strNewName . '/' . $objFiles->name;
					$objNewFile->save();
				}
			}

			// Copy the folder
			$return = $this->Files->rcopy($this->strFolder, $strNewName);

			// Update the MD5 hash of the parent folders
			if ($strParent != $GLOBALS['TL_CONFIG']['uploadPath'])
			{
				\Dbafs::updateFolderHashes($strParent);
			}
			if (($strPath = dirname($this->strFolder)) != $GLOBALS['TL_CONFIG']['uploadPath'])
			{
				\Dbafs::updateFolderHashes($strPath);
			}
		}

		return $return;
	}


	/**
	 * Protect the folder by adding an .htaccess file
	 */
	public function protect()
	{
		if (!file_exists(TL_ROOT . '/' . $this->strFolder . '/.htaccess'))
		{
			\File::putContent($this->strFolder . '/.htaccess', "<IfModule !mod_authz_core.c>\n  Order deny,allow\n  Deny from all\n</IfModule>\n<IfModule mod_authz_core.c>\n  Require all denied\n</IfModule>");
		}
	}


	/**
	 * Unprotect the folder by removing the .htaccess file
	 */
	public function unprotect()
	{
		if (file_exists(TL_ROOT . '/' . $this->strFolder . '/.htaccess'))
		{
			$objFile = new \File($this->strFolder . '/.htaccess', true);
			$objFile->delete();
		}
	}


	/**
	 * Return the MD5 hash of the folder
	 *
	 * @return string The MD5 has
	 */
	protected function getHash()
	{
		$arrFiles = array();

		$it = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator(TL_ROOT . '/' . $this->strFolder, \FilesystemIterator::UNIX_PATHS)
		);

		while ($it->valid())
		{
			if ($it->isFile() && $it->getFilename() != '.DS_Store')
			{
				$arrFiles[] = $it->getSubPathname();

				// Do not try to hash if bigger than 2 GB
				if ($it->getSize() >= 2147483648)
				{
					return '';
				}
				else
				{
					$arrFiles[] = md5_file($it->getPathname());
				}
			}

			$it->next();
		}

		return md5(implode('-', $arrFiles));
	}


	/**
	 * Return the size of the folder
	 *
	 * @return integer The folder size in bytes
	 */
	protected function getSize()
	{
		$intSize = 0;

		foreach (scan(TL_ROOT . '/' . $this->strFolder) as $strFile)
		{
			if ($strFile == '.svn' || $strFile == '.DS_Store')
			{
				continue;
			}

			if (is_dir(TL_ROOT . '/' . $this->strFolder . '/' . $strFile))
			{
				$objFolder = new \Folder($this->strFolder . '/' . $strFile);
				$intSize += $objFolder->size;
			}
			else
			{
				$objFile = new \File($this->strFolder . '/' . $strFile, true);
				$intSize += $objFile->size;
			}
		}

		return $intSize;
	}
}

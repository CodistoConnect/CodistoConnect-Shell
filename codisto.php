<?php
/**
* Codisto eBay Sync Extension
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@magentocommerce.com so we can send you a copy immediately.
*
* @category    Codisto
* @package     Codisto_Sync
* @copyright   Copyright (c) 2015 On Technology Pty. Ltd. (http://codisto.com/)
* @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
*/

require_once 'abstract.php';

class Codisto_Shell extends Mage_Shell_Abstract
{
	public function run()
	{
		if($this->getArg('uninstall'))
		{
			$tablePrefix = Mage::getConfig()->getTablePrefix();
			
			$adapter = Mage::getModel('core/resource')->getConnection(Mage_Core_Model_Resource::DEFAULT_READ_RESOURCE);

			$triggers = $adapter->fetchAll(
							'SELECT T.TRIGGER_NAME, '.
									'T.TRIGGER_SCHEMA, '.
									'T.TRIGGER_NAME, '.
									'T.EVENT_MANIPULATION, '.
									'T.EVENT_OBJECT_SCHEMA, '.
									'T.EVENT_OBJECT_TABLE, '.
									'T.ACTION_STATEMENT, '.
									'T.ACTION_TIMING, '.
									'T.DEFINER, '.
									'T.SQL_MODE '.
							'FROM INFORMATION_SCHEMA.TRIGGERS AS T '.
							'WHERE ACTION_STATEMENT LIKE \'%/* start codisto change tracking trigger */%\'');
							
			foreach($triggers as $trigger)
			{
				$current_statement = preg_replace('/^BEGIN|END$/i', '', $trigger['ACTION_STATEMENT']);
				$cleaned_statement = trim(preg_replace('/\s*\/\*\s+start\s+codisto\s+change\s+tracking\s+trigger\s+\*\/.*\/\*\s+end\s+codisto\s+change\s+tracking\s+trigger\s+\*\/\n?\s*/is', '', $current_statement));

				if($cleaned_statement == '')
				{
					echo "Removing trigger ".$trigger['TRIGGER_NAME']."...";
					
					$adapter->query('DROP TRIGGER `'.$trigger['TRIGGER_SCHEMA'].'`.`'.$trigger['TRIGGER_NAME'].'`');
					
					echo "Removed\n";
				}
				else
				{
					echo "Removing codisto modifications from trigger ".$trigger['TRIGGER_NAME']."...";
					
					$definer = $trigger['DEFINER'];
					if(strpos($definer, '@') !== false)
					{
						$definer = explode('@', $definer);
						$definer[0] = '\''.$definer[0].'\'';
						$definer[1] = '\''.$definer[1].'\'';
						$definer = implode('@', $definer);
					}

					$adapter->query('SET @saved_sql_mode = @@sql_mode');
					$adapter->query('SET sql_mode = \''.$trigger['SQL_MODE'].'\'');
					$adapter->query('DROP TRIGGER `'.$trigger['TRIGGER_SCHEMA'].'`.`'.$trigger['TRIGGER_NAME'].'`');
					$adapter->query('CREATE DEFINER = '.$definer.' TRIGGER `'.$trigger['TRIGGER_NAME'].'` '.$trigger['ACTION_TIMING'].' '.$trigger['EVENT_MANIPULATION'].' ON `'.$trigger['EVENT_OBJECT_TABLE'].'`'.
					"\n FOR EACH ROW BEGIN\n".$cleaned_statement."\n\nEND");
					$adapter->query('SET sql_mode = @saved_sql_mode');
					
					echo "Removed\n";
				}
			}
			
			echo "Removing codisto_product_change table...";
			$adapter->query('DROP TABLE IF EXISTS `'.$tablePrefix.'codisto_product_change`');
			echo "Removed\n";
			
			echo "Removing codisto_order_change table...";
			$adapter->query('DROP TABLE IF EXISTS `'.$tablePrefix.'codisto_order_change`');
			echo "Removed\n";
			
			echo "Removing codisto_category_change table...";
			$adapter->query('DROP TABLE IF EXISTS `'.$tablePrefix.'codisto_category_change`');
			echo "Removed\n";
			
			echo "Removing codisto_sync table...";
			$adapter->query('DROP TABLE IF EXISTS `'.$tablePrefix.'codisto_sync`');
			echo "Removed\n";
			
			if($this->getArg('complete'))
			{
				echo "Removing codisto_trigger_backup table...";
				$adapter->query('DROP TABLE IF EXISTS `'.$tablePrefix.'codisto_trigger_history`');
				echo "Removed\n";
			}

			echo "\n";
		}
		else if($this->getArg('reindex'))
		{
			Mage::helper('codistosync')->eBayReIndex();
			
			echo "OK\n";
		}
		else if($this->getArg('status'))
		{
			$stores = Mage::getModel('core/store')->getCollection();
			$stores->setLoadDefault(true);
			$stores->clear()->setOrder('store_id', 'ASC');
			
			foreach($stores as $store)
			{
				$config = Zend_Json::decode($store->getConfig('codisto/merchantid'));
				if(!is_array($config))
				{
					$config = array($config);
				}
				
				$hostkey;
				if($this->getArg('all'))
				{
					$hostkey = $store->getConfig('codisto/hostkey');
				}

				echo $store->getId()."\t".$store->getCode()."\t".implode(',', $config).(isset($hostkey) ? "\t".$hostkey : '')."\n";	
			}
		}
		else
		{
			echo $this->usageHelp();
			echo "\n";
		}
	}
	
	public function usageHelp()
	{
		return <<<USAGE
Usage:  php -f codisto.php -- [options]
        php -f codisto.php -- uninstall [complete]
        php -f codisto.php -- reindex
        php -f codisto.php -- status [all]

  uninstall         Remove all standalone triggers, trigger modifications and codisto database tables
  complete          Removes the codisto_trigger_history table
  reindex           Start a full index of the catalog
  status            Show codisto configuration per store view
  all               Show codisto secret host key per store view
  help              This help

USAGE;
	}

};

$shell = new Codisto_Shell();
$shell->run();


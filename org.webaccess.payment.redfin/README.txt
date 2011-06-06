// RedFin Payment Processor Installation

 This Payment Processor you can install by creating extension folder and 
 creating  extension directory path in civicrm.

 diff.txt file is having custom code which you need to add in the custom 
 file to send the recurring contribution mails Path: CRM/Contribute/BAO/Contribution/Utils.php

 RedfinIPN.php file you need to put in the civicrm/bin folder

 You need to make the cron file for recurring contribution One for live contribution and one for test contribution to test it properly.

 For eg: 

 For Test: Basepath/sites/all/modules/civicrm/bin/RedfinIPN.php?is_test=1&name=drupalusername&pass=drupalpassword&key=civicrm sitekey

 For Live: Basepath/sites/all/modules/civicrm/bin/RedfinIPN.php?name=drupalusername&pass=drupalpassword&key=civicrm sitekey

 And you need to set the cron for every hour.

 //

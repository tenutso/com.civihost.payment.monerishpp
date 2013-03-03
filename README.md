SETUP INSTRUCTIONS

Login to your eSelect admin panel and generate a Version 3 Configuration.

Main Configuration
------------------
Transaction Type = Purchase
Response Method = GET
Approved URL = https://<yourdomain>/path/to/civicrm/extern/hpp.php
Declined URL = https://<yourdomain>/path/to/civicrm/extern/hpp.php

Appearance
----------
Cancel Button URL = http://<yourdomain>
Choose available credit cards from the list (depends on your bank)

Security
Enable Transaction Verification = True
Response Method = Displayed as XML on our server

If this extension becomes popular I'll be happy to work on recurring payments.
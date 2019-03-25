# Using the Jeeb plugin for Prestashop

## Last Cart Versions Tested: 1.6.1.14

## Prerequisites
You must have a Jeeb merchant account to use this plugin.  It's free to [sign-up for a Jeeb merchant account](https://jeeb.io).


## Server Requirements

+ PrestaShop 1.6
+ PHP 5+
+ Curl PHP Extension
+ JSON PHP Extension

## Plugin Configuration

### For Prestashop versions 1.6:
1. Clone this repository and make a zip file of **jeeb** folder or download the latest release.
2. Go to your PrestaShop administration. Under "Modules and services" select "Add new module" (v1.6)
3. Go to your "installed modules" -> "Jeeb" and click [Configure]<br />
4. Create a signature in your Jeeb account at jeeb.io .<br />
5. Enter your signature from step 4.
6. Choose which environment you want (Live/Test).
7. Set a Base currency(It usually should be the currency of your store) and Target Currency(It is a multi-select option. You can choose any cryptocurrency from the listed options.).
8. Set the language of the payment page (you can set Auto-Select to auto detecting manner).

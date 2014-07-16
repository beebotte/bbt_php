Beebotte PHP SDK
================

| what          | where                                  |
|---------------|----------------------------------------|
| overview      | http://beebotte.com/overview           |
| tutorials     | http://beebotte.com/tutorials          |
| apidoc        | http://beebotte.com/api                |
| source        | https://github.com/beebotte/bbt_php    |

### Bugs / Feature Requests

Think you,ve found a bug? Want to see a new feature in beebotte? Please open an
issue in github. Please provide as much information as possible about the issue type and how to reproduce it.

  https://github.com/beebotte/bbt_php/issues

## Install

The Beebotte PHP library is available as a `composer` package called `bbt_php` (See <https://packagist.org/packages/beebotte/bbt_php>). 
If you don't have composer, you can still get Beebotte PHP library by cloning the project from github `git clone <https://github.com/beebotte/bbt_php.git>` or by downloading the library source files.

## Usage
To use the library, you need to be a registered user. If this is not the case, create your account at <http://beebotte.com> and note your access credentials.

As a reminder, Beebotte resource description uses a two levels hierarchy:

* Channel: physical or virtual connected object (an application, an arduino, a coffee machine, etc) providing some resources
* Resource: most elementary part of Beebotte, this is the actual data source (e.g. temperature from a domotics sensor)
  
### Beebotte Constructor
Use your account api and secret keys to initialize Beebotte connector:

    $api_key    = 'YOUR_API_KEY';
    $secret_key = 'YOUR_SECRET_KEY';
    $bbt = new Beebotte($api_key, $secret_key);

### Reading Data
You can read data from one of your channel resources using:

    $records = $bbt->read("channel1", "resource1", 5 /* read last 5 records */);
    
You can read data from a public channel by specifying the channel owner:

    $records = $bbt->publicRead("owner", "channel1", "resource1", 5 /* read last 5 records */);
    
### Writing Data
You can write data to a resource of one of your channels using:

    $bbt->write("channel1", "resource1", "Hello Horld");
    
If you have multiple records to write (to one or multiple resources of the same channel), you can use the `bulk write` method:

    $bbt->writeBulk("channel1", array(array("resource" => "resource1", "data" => "Hello"), array("resource" => "resource2", "data" => "World")));

### Publishing Data
You can publish data to a channel resource using:

    $bbt->publish("any_channel", "any_resource", "Hello Horld");

Published data is transient. It will not saved to any database; rather, it will be delivered to active subscribers in real time. 
The Publish operations do not require that the channel and resource be actually created. 
They will be considered as virtual: the channel and resource exist as lng as you are publishing data to them. 
By default, published data is public, publish a private message, you need to add `private-` prefix to the channel name like this:

    $bbt->publish("private-any_channel", "any_resource", "Hello Horld");

If you have multiple records to publish (to one or multiple resources of the same channel), you can use the `bulk publish` method:

    $bbt->publishBulk("channel1", array(array("resource" => "resource1", "data" => "Hello"), array("resource" => "resource2", "data" => "World")));

### Resource Object
The library provides a Resource Class that can be used as follows

    //Create the resource object
    $resource = new Resource($bbt, "channel1", "resource1");
    
    //Read data from public resource
    $records = $resource->read("owner", 2 /* last 2 records */);
    
    //Read data
    $records = $resource->read(null, 2 /* last 2 records */);
    
    //Read the last written record
    $record = $resource->recentValue();
    
    //Write data
    $resource->write("Hello World");
    
    //Publish data
    $resource->publish("Hola amigo");

## License
Copyright 2013 - 2014 Beebotte.

[The MIT License](http://opensource.org/licenses/MIT)
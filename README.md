# Aerones Downloader

## Overview

Aerones Downloader is a high-performance file downloader built with **Symfony 7**, **PHP 8.2**, and **ReactPHP**. It is designed to support **concurrent downloads** of large files while ensuring **resume functionality** (both manual and automatic). In case of connection failures, the system retries downloads up to a fixed number of times.

The project is containerized with **Lando**, making it easy to set up and review.

## Features

- Concurrent downloads for high efficiency
- Resume functionality on failures
- Automatic retries with a fixed limit
- Uses **Lando** for easy local development

## Prerequisites

Ensure that you have the following installed on your system before proceeding:

- **Lando** ([Installation Guide](https://docs.lando.dev/basics/installation.html))
- **Docker** (as required by Lando)

## Quick Setup Instructions

### 1. Install Lando

If you haven't installed **Lando** yet, follow these steps:

#### macOS

```sh
brew install --cask lando
```

#### Linux

```sh
curl -fsSL https://github.com/lando/lando/releases/latest/download/lando-x64.deb -o lando.deb
sudo dpkg -i lando.deb
```

#### Windows

Download and install **Lando** from [Lando's official site](https://docs.lando.dev/basics/installation.html#windows).

### 2. Clone the Repository

```sh
git clone https://github.com/YOUR_USERNAME/aerones-downloader.git
cd aerones-downloader
```

### 3. Start Lando Environment

```sh
lando start
```

This command initializes the environment and starts all necessary services for review and testing.


## Reviewing the Project

Once the environment is set up, you can start reviewing the code and functionality:

- **Backend:** Symfony 7 API (`src/` directory)
- **Downloader Logic:** Implemented with **ReactPHP**
- **Database Management:** PostgreSQL 15
- **Services Configuration:** Defined in `.lando.yml`

### Running the Application

To test the downloader, just start lando. Fixtures and consumers will be run and the link to the page will be given where downloads may be activated in any order / variety.
The refresh rate of the update progress on the page is less responsive intentianllly (less requests to not bloat the service), same goes for the updating DB progress during the DL process.
It is better to chek out consuemr logs in the console or var/log/dev.log for process details and see the filesize changes in the /var/temp folder

### Some background thought process and decision making

Initiall plan was to make a cli command, but i decided to challende myself with a messenge queue / controller and a bit of frontend setup.
Current message handler is a bit of a monster and probably the weakest part of this version due to being a blocking processm but being able to handle arbitraty number of concurrent downloads.

## Stopping the Environment

To stop the Lando environment, run:

```sh
lando stop
```

To completely remove the environment:

```sh
lando destroy
```

## Additional Information

For more details on available Lando commands, run:

```sh
lando list
```

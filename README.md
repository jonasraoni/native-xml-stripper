# Native XML Stripper

For now the purpose of this tool is to strip non-published data from the Native XML plugin's output and facilitate the import process.

It currently works with the OJS 3.3 format, other releases were not tested, and it's probably not going to work.

## Usage

Running the script will display a helpful text with the possible arguments.
```sh
php native-xml-stripper.php
```

## Details

Considering the sample commands below:

```sh
native-xml-stripper.php -i input/original-issue-1.xml -o output/clean-issue-1.xml -u jonas -a Author -h instructions.md -j publicknowledge
native-xml-stripper.php -i input/original-issue-2.xml -o output/clean-issue-2.xml -u jonas -a Author -h instructions.md -j publicknowledge
```

- The tool will receive two Native XML files and output them to the specified paths without non-published data.
- In order to facilitate the data import, the tool will collect the genres and locales used by all the processed files, this data is accumulated on the file `data.json`, which will be created on the first run. Therefore, if you're going to import data into different journals, it's needed to delete this file between each import set.
- The tool will set the uploader username to `jonas` and the user group for authors to `Author`.
- At the end of every command, a file `instructions.md` will be created, it contains instructions about how to proceed and a script to prepare the destiny database with the required genres.

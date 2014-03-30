<?php
class CAMRobTransform
{
	private $input_file;

	private $file_name;

	private $file_contents;

	private $lin_length = 800;

	private $lin_files;

	private $base_name;

	private $zipfile;

	/**
	 * Start splitting the given file
	 *
	 * @param string $input_file Absolute path to src file to process
	 * @param string $filename Filename to use when creating the zip file
	 */
	public function __construct ($input_file, $filename)
	{
		$this->parse_filename($input_file, $filename);

		$this->zip_create();
		$this->zipfile_name = $this->zipfile->filename;

		$this->file_contents = file_get_contents($input_file);

		$this->update_header();
		$this->update_approx();
		$this->update_feedrate();
		$this->parse_lin_lines();
		$this->zip_add_lin_files();
		$this->update_end();

		$this->zipfile->close();
	}

	/**
	 * Replace the fixed header
	 */
	private function update_header()
	{
		$header_in = file_get_contents(__DIR__.'/CamRobSKRL/header_in.txt');
		$header_out = file_get_contents(__DIR__.'/CamRobSKRL/header_out.txt');

		$header_in = str_replace("\n", "\r\n", $header_in);

		$this->file_contents = str_replace($header_in, $header_out, $this->file_contents);
	}

	private function update_approx()
	{
		$approx_in  = file_get_contents(__DIR__.'/CamRobSKRL/approx_in.txt');
		$approx_out = file_get_contents(__DIR__.'/CamRobSKRL/approx_out.txt');

		$approx_in = str_replace("\n", "\r\n", $approx_in);

		$this->file_contents = str_replace($approx_in, $approx_out, $this->file_contents);
	}

	private function update_feedrate()
	{
		$search =   "/;Fold Set user params\r\n".
					"CR_rPARAMS\[1\] = \d+\s+;LineNr\r\n".
					"CR_rPARAMS\[2\] = ([\d.]+)\s+;Feedrate m\/s\r\n".
					"CR_rPARAMS\[3\] = \d+\s+;Spindle on\/off\r\n".
					"CR_rPARAMS\[4\] = \d+\s+;SpindleSpeed rpm\r\n".
					"CR_rPARAMS\[5\] = \d\s+;Spindle rot\r\n".
					"CR_rPARAMS\[6\] = \d\s+;Coolant\r\n".
					"CR_rPARAMS\[7\] = \d\s+;ToolNo\r\n".
					"CR_rPARAMS\[8\] = [\d.]+\s+;OptimAcc m\/s2\r\n".
					"CR_USER_PARAMS_ADV \(\)\r\n".
					"TRIGGER WHEN DISTANCE = 0 DELAY = 0 DO CR_USER_PARAMS_TRIG \(\) PRIO = -1\r\n".
					";Endfold\r\n/";

		$this->file_contents = preg_replace($search, "\$VEL.CP = \\1\r\n", $this->file_contents);
	}

	private function update_end()
	{
		$end_out = file_get_contents(__DIR__.'/CamRobSKRL/end_out.txt');
		$this->file_contents .= $end_out;

		$this->zipfile->addFromString("{$this->basename}/{$this->basename}.src", $this->file_contents);
	}

	private function parse_lin_lines()
	{
		$lines = explode("\n", $this->file_contents);

		$total = count($lines);
		$start = 0;
		$end = 0;

		for ($c = 0; $c < $total; $c++)
		{
			if (strpos($lines[$c], "LIN") === 0)
			{
				$start = $c;
				break;
			}
		}

		for ($c = $total-1; $c > 0; $c--)
		{
			if (strpos($lines[$c], "END") === 0)
			{
				$end = $c-1;
				break;
			}
		}

		$length = $end - $start;

		$lin_lines = array_slice($lines, $start, $length);

		$this->lin_files = array_chunk($lin_lines, $this->lin_length);

		$header = array_slice($lines, 0, $start-1);
		$this->file_contents = implode("\r\n", $header)."\r\n";
	}

	/**
	 * Add the parsed LIN files to the zip file
	 *
	 * @return bool Success state
	 */
	private function zip_add_lin_files()
	{
		if (empty($this->lin_files) == true)
		{
			$this->last_error = "No LIN parts available.";
			return false;
		}

		for ($c = 0; $c < count($this->lin_files); $c++)
		{
			$lin_part = $c+1;

			$filename = "{$this->basename}/{$this->basename}_{$lin_part}.src";
			$contents = "DEF {$this->basename}_{$lin_part}()\r\n".implode("\r\n", $this->lin_files[$c])."END\r\n";

			if ($this->zipfile->addFromString($filename, $contents) === false)
			{
				$this->last_error = "Failed to add LIN part file.";
				return false;
			}

			$this->file_contents .= "{$this->basename}_{$lin_part}()\r\n";
		}

		return true;
	}

	/*
	 * Creates a temporary zip file in the working directory
	 *
	 * @return bool Success status
	 */
	private function zip_create()
	{
		$this->zipfile = new ZipArchive();

		if ($this->zipfile === false)
		{
			$this->last_error = "ZipArchive not available";
			return false;
		}

		// create a temporary file for the zip
		$tempname = tempnam(sys_get_temp_dir(), $this->basename).'.zip';

		$this->zipfile->open($tempname, ZIPARCHIVE::CREATE);
		$this->zipfile->addEmptyDir($this->basename);

		return true;
	}

	/**
	 * Closes the open zip file
	 *
	 * @return bool True if zip file closed properly, false otherwise
	 */
	private function zip_close()
	{
		return $this->zipfile->close();
	}

	/**
	 * Delete the zip file once download is complete
	 *
	 * @return bool
	 */
	public function zip_remove()
	{
		if (empty($this->zipfile) === false)
		{
			return unlink($this->zipfile->filename);
		}
	}


	/**
	 * Retrieves the filename of the zip file opened
	 *
	 * @return string Absolute path to the zip file
	 */
	public function zip_filename()
	{
		return $this->zipfile_name;
	}

	/*
	 * Strip the bits we need out of our input filename
	 *
	 * @input string $input_file Absolute path to file to process
	 * @input string $filename Filename to store results in
	 *
	 * @return bool Success state
	 */
	private function parse_filename ($input_file, $filename)
	{
		$realpath = realpath($input_file);
		$pathinfo = pathinfo($realpath);

		$filename = explode('.', $filename);

		$this->basedir 	= $pathinfo['dirname'];
		$this->basename = $filename[0];

		return true;
	}
}
<?php
class KUKATransform
{
	var $basedir;
	var $basename;
	var $lin_files = Array();
    var $zipfile;
    var $zipfile_name;
    var $last_error;
	
    /**
     * Start splitting the given file
     *
     * @param string $input_file Absolute path to src file to process
     * @param string $filename Filename to use when creating the zip file
     * @param int $lin_lines_per_file Number of LIN lines per file split
     */
    public function __construct ($input_file, $filename, $lin_lines_per_file = 8000)
	{
		$this->parse_filename($input_file, $filename);
		$this->parse_lin_file($input_file, $lin_lines_per_file);

        $this->zip_create();

        if ($this->zipfile)
        {
            $this->zipfile_name = $this->zipfile->filename;

            $this->zip_add_lin_files();

            if ($this->zip_add_src_file() === false)
            {
                return false;
            }

            if ($this->zip_add_dat_file() === false)
            {
                return false;
            }

            $this->zipfile->close();
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
        }

        return true;
    }

    /**
     * Adds a customised version of the known TEMPLATE.DAT file
     *
     * @return bool Success state
     */
    private function zip_add_src_file()
    {
        $template_src = file_get_contents(__DIR__."/TEMPLATE.SRC");

        if (empty($template_src) === true)
        {
            $this->last_error = "Failed to read template file ".__DIR__."/TEMPLATE.SRC";
            return false;
        }

        $lin_callers = "";

        for ($c = 0; $c < count($this->lin_files); $c++)
        {
            $lin_callers .= "{$this->basename}_".($c+1)."()\r\n";
        }

        $template_src = str_replace("Template_Base1", $this->basename, $template_src);
        $template_src = str_replace("##SRC_FILES##", $lin_callers, $template_src);

        return $this->zipfile->addFromString("{$this->basename}/{$this->basename}.src", $template_src);
    }

    /**
     * Creates a customised version of TEMPLATE.DAT and adds to the Zip file
     *
     * @return bool Success status
     */
    private function zip_add_dat_file()
    {
        $template_dat = file_get_contents(__DIR__."/TEMPLATE.DAT");

        if (empty($template_dat) === true)
        {
            $this->last_error = "Failed to read template file ".__DIR__."/TEMPLATE.DAT";
            return false;
        }

        $template_dat = str_replace("DEFDAT  TEMPLATE_BASE1", "DEFDAT {$this->basename}", $template_dat);

        return $this->zipfile->addFromString("{$this->basename}/{$this->basename}.dat", $template_dat);
    }

	/*
	 * Extract LIN lines from the given file and split into correct chunks
	 *
	 * @input string $filename Name of file to parse
	 * @input int $lines_per_file Number of LIN lines per file
	 *
	 * @return array Indexed array of chunks (array of lines)
	 */
	private function parse_lin_file ($filename, $lines_per_file)
	{
		$contents = file_get_contents($filename);

        if ($contents == false) {
            return false;
        }

        $lines = Array();

        // find all lines beginning with "LIN "
        preg_match_all("/^LIN (.*)$/m", $contents, $lines);

        if (isset($lines[0]) === false)
        {
            return false;
        }

        // split the result into X lines per file
        $this->lin_files = array_chunk($lines[0], $lines_per_file);

        return true;
	}
}

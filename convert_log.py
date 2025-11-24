import json
import sys
import os

def convert_log_to_json(input_file, output_file):
    """
    Reads a JSONL file and converts it to a pretty-printed JSON array file.
    Each line in the input file is expected to be a valid JSON object.
    """
    json_objects = []
    print(f"Reading from {input_file}...")
    try:
        with open(input_file, 'r') as f:
            for i, line in enumerate(f):
                line = line.strip()
                if line:
                    try:
                        json_objects.append(json.loads(line))
                    except json.JSONDecodeError:
                        print(f"Warning: Skipping invalid JSON on line {i+1} in {input_file}", file=sys.stderr)
    except FileNotFoundError:
        print(f"Error: Input file not found: {input_file}", file=sys.stderr)
        return

    print(f"Writing to {output_file}...")
    try:
        with open(output_file, 'w') as f:
            json.dump(json_objects, f, indent=4)
        print(f"Successfully converted {input_file} to {output_file}")
    except IOError as e:
        print(f"Error writing to output file: {e}", file=sys.stderr)


if __name__ == "__main__":
    if len(sys.argv) != 2:
        print("Usage: python convert_log.py <input_log_file>", file=sys.stderr)
        sys.exit(1)

    input_log = sys.argv[1]
    
    # Derive output filename by changing the extension to .json
    base_name = os.path.splitext(input_log)[0]
    output_json = base_name + ".json"
    
    convert_log_to_json(input_log, output_json)

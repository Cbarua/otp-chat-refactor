import sys
import json
import os
from datetime import datetime

def parse_log_file(file_path):
    """Parses a log file and groups entries by visitor_id."""
    grouped_logs = {}
    
    try:
        with open(file_path, 'r', encoding='utf-8') as f:
            for line_num, line in enumerate(f, 1):
                line = line.strip()
                if not line:
                    continue
                
                try:
                    entry = json.loads(line)
                    
                    # Extract visitor_id
                    visitor_id = "Unknown"
                    if "extra" in entry and "visitor_id" in entry["extra"]:
                        visitor_id = entry["extra"]["visitor_id"]
                    
                    if visitor_id not in grouped_logs:
                        grouped_logs[visitor_id] = []
                    
                    grouped_logs[visitor_id].append(entry)
                    
                except json.JSONDecodeError:
                    print(f"Warning: Could not parse line {line_num} in {file_path}")
                except Exception as e:
                    print(f"Error processing line {line_num} in {file_path}: {e}")

    except FileNotFoundError:
        print(f"Error: File not found: {file_path}")
        return None
    except Exception as e:
        print(f"Error reading file {file_path}: {e}")
        return None
        
    return grouped_logs

def format_entry(entry):
    """Formats a single log entry for display."""
    timestamp = entry.get("datetime", "N/A")
    level = entry.get("level_name", "UNKNOWN")
    message = entry.get("message", "")
    context = entry.get("context", {})
    
    # Format timestamp to be more readable if possible
    # Assuming ISO format like 2025-11-25T00:06:56.821347+05:30
    try:
        dt = datetime.fromisoformat(timestamp)
        timestamp = dt.strftime("%Y-%m-%d %H:%M:%S")
    except ValueError:
        pass # Keep original if parsing fails

    output = []
    output.append(f"[{timestamp}] {level}: {message}")
    
    if context:
        # Pretty print context
        context_str = json.dumps(context, indent=4)
        # Indent the context block
        indented_context = "\n".join("    " + line for line in context_str.splitlines())
        output.append(indented_context)
        
    return "\n".join(output)

def process_file(file_path):
    """Processes a single log file."""
    print(f"Processing {file_path}...")
    
    grouped_logs = parse_log_file(file_path)
    if grouped_logs is None:
        return

    # Determine output filename
    base_name = os.path.splitext(file_path)[0]
    output_path = f"{base_name}.txt"
    
    try:
        with open(output_path, 'w', encoding='utf-8') as out:
            # Sort visitor_ids for consistent output
            sorted_visitor_ids = sorted(grouped_logs.keys())
            
            for visitor_id in sorted_visitor_ids:
                entries = grouped_logs[visitor_id]
                
                # Header for visitor
                out.write("=" * 80 + "\n")
                out.write(f"VISITOR ID: {visitor_id}\n")
                out.write("=" * 80 + "\n\n")
                
                for entry in entries:
                    out.write(format_entry(entry))
                    out.write("\n\n" + "-" * 40 + "\n\n")
                
                out.write("\n\n")
                
        print(f"Successfully created {output_path}")
        
    except Exception as e:
        print(f"Error writing to {output_path}: {e}")

def main():
    if len(sys.argv) < 2:
        print("Usage: python log_organizer.py <log_file1> [log_file2 ...]")
        sys.exit(1)
        
    for file_path in sys.argv[1:]:
        process_file(file_path)

if __name__ == "__main__":
    main()
